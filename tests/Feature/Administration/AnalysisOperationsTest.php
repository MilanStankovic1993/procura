<?php

use App\Actions\Analyses\CreateBuyAnalysisDraft;
use App\Actions\Analyses\RequestManualAnalysisRetry;
use App\Actions\Analyses\SubmitAnalysis;
use App\Actions\Listings\CreateListing;
use App\Actions\Markets\SyncMarketReferenceData;
use App\Analysis\Operations\AnalysisOperationsQuery;
use App\Enums\Analyses\AnalysisDispatchStatus;
use App\Enums\Analyses\AnalysisStatus;
use App\Exceptions\AnalysisRetryConflictException;
use App\Filament\Resources\AnalysisOperations\AnalysisOperationResource;
use App\Filament\Resources\AnalysisOperations\Pages\ListAnalysisOperations;
use App\Jobs\ProcessBuyAnalysis;
use App\Models\Analysis;
use App\Models\AnalysisDispatch;
use App\Models\AnalysisRetryEvent;
use App\Models\MarketplaceSource;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\PlatformAuditEvent;
use App\Models\SubscriptionUsage;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    config(['analyses.manual_retry_enabled' => true]);
    app(SyncMarketReferenceData::class)->sync();
    $this->seed(PlanSeeder::class);
});

function analysisOperationsSuperAdmin(array $attributes = []): User
{
    $operator = User::factory()->create($attributes);
    $operator->forceFill(['is_super_admin' => true])->save();

    return $operator->fresh();
}

/**
 * @return array{
 *     analysis: Analysis,
 *     dispatch: AnalysisDispatch,
 *     organization: Organization,
 *     requester: User
 * }
 */
function analysisOperationsFailedFixture(
    string $failureMessage = 'Provider token secret-analysis-value must stay private.',
): array {
    $requester = User::factory()->create();
    $organization = Organization::factory()->create([
        'name' => 'Analysis Operations Workspace',
    ]);
    OrganizationMembership::factory()->owner()->create([
        'organization_id' => $organization,
        'user_id' => $requester,
    ]);
    $requester->forceFill([
        'current_organization_id' => $organization->getKey(),
    ])->save();
    $source = MarketplaceSource::factory()->create();
    $listing = app(CreateListing::class)->create(
        $organization,
        $requester,
        $source,
        [
            'source_url' => 'https://operations.example/listings/failed-analysis',
            'external_id' => 'failed-analysis',
            'marketplace_name' => 'Operations Market',
            'title' => 'Professional camera for operations review',
            'description' => 'Complete camera kit with charger and two batteries.',
            'asking_price_minor' => 89900,
            'currency_code' => 'EUR',
            'seller_information' => 'Private seller.',
            'location' => 'Vienna',
            'source_country_code' => 'AT',
            'target_country_code' => 'DE',
            'status' => 'active',
            'notes' => null,
        ],
    );
    $analysis = app(CreateBuyAnalysisDraft::class)->create(
        $organization,
        $requester,
        $listing->getKey(),
        'DE',
    );
    $analysis = app(SubmitAnalysis::class)->submit(
        $organization,
        $requester,
        $analysis->getKey(),
    );
    $dispatch = $analysis->currentDispatch()->firstOrFail();
    (new ProcessBuyAnalysis(
        $analysis->getKey(),
        $dispatch->getKey(),
    ))->failed(new RuntimeException($failureMessage));

    return [
        'analysis' => $analysis->fresh(),
        'dispatch' => $dispatch->fresh(),
        'organization' => $organization,
        'requester' => $requester,
    ];
}

test('manual analysis retry is authorized, immutable, idempotent, and does not consume quota twice', function () {
    Queue::fake();
    $fixture = analysisOperationsFailedFixture();
    $analysis = $fixture['analysis'];
    $dispatch = $fixture['dispatch'];
    $operator = analysisOperationsSuperAdmin([
        'email' => 'analysis-operator@example.test',
    ]);
    $idempotencyKey = (string) Str::uuid();
    $reason = 'Provider incident is resolved and the input was reviewed.';
    $action = app(RequestManualAnalysisRetry::class);

    expect(fn () => $action->request(
        $analysis,
        User::factory()->create(),
        $dispatch->getKey(),
        (string) Str::uuid(),
        $reason,
    ))->toThrow(AuthorizationException::class);
    expect(fn () => $action->request(
        $analysis,
        analysisOperationsSuperAdmin(['email_verified_at' => null]),
        $dispatch->getKey(),
        (string) Str::uuid(),
        $reason,
    ))->toThrow(AuthorizationException::class);

    $result = $action->request(
        analysis: $analysis,
        operator: $operator,
        expectedCurrentDispatchId: $dispatch->getKey(),
        idempotencyKey: $idempotencyKey,
        reason: $reason,
        ipAddress: '192.0.2.25',
        userAgent: 'Procura operations test',
    );
    $retriedAnalysis = $result['analysis'];
    $newDispatch = $result['dispatch'];
    $retryEvent = $result['retry_event'];

    expect($result['created'])->toBeTrue()
        ->and($retriedAnalysis->status)->toBe(AnalysisStatus::Queued)
        ->and($retriedAnalysis->next_retry_at)->toBeNull()
        ->and($retriedAnalysis->last_error_code)->toBeNull()
        ->and($retriedAnalysis->last_error_message)->toBeNull()
        ->and($newDispatch->run_number)->toBe(2)
        ->and($newDispatch->status)->toBe(AnalysisDispatchStatus::Dispatched)
        ->and($newDispatch->max_processing_attempts)->toBe(3)
        ->and($retryEvent->previous_dispatch_id)->toBe($dispatch->getKey())
        ->and($retryEvent->new_dispatch_id)->toBe($newDispatch->getKey())
        ->and($retryEvent->previous_error_code)->toBe('RuntimeException')
        ->and($retryEvent->previous_error_hash)->toBe(hash(
            'sha256',
            'Provider token secret-analysis-value must stay private.',
        ))
        ->and(json_encode($retryEvent->getAttributes()))
        ->not->toContain('secret-analysis-value')
        ->and(AnalysisDispatch::query()->count())->toBe(2)
        ->and(AnalysisRetryEvent::query()->count())->toBe(1)
        ->and(SubscriptionUsage::query()->sole()->used)->toBe(1)
        ->and(PlatformAuditEvent::query()
            ->where('action', 'analysis.manual_retry_requested')
            ->count())->toBe(1);
    Queue::assertPushed(ProcessBuyAnalysis::class, 2);

    $replay = $action->request(
        $analysis,
        $operator,
        $dispatch->getKey(),
        $idempotencyKey,
        $reason,
    );

    expect($replay['created'])->toBeFalse()
        ->and($replay['dispatch']->is($newDispatch))->toBeTrue()
        ->and(AnalysisDispatch::query()->count())->toBe(2)
        ->and(AnalysisRetryEvent::query()->count())->toBe(1)
        ->and(PlatformAuditEvent::query()
            ->where('action', 'analysis.manual_retry_requested')
            ->count())->toBe(1);
    Queue::assertPushed(ProcessBuyAnalysis::class, 2);

    expect(fn () => $action->request(
        $analysis,
        $operator,
        $dispatch->getKey(),
        $idempotencyKey,
        'A different reason cannot reuse the same idempotency key.',
    ))->toThrow(
        AnalysisRetryConflictException::class,
        'The idempotency key was already used',
    );
    expect(fn () => $action->request(
        $analysis,
        $operator,
        $dispatch->getKey(),
        (string) Str::uuid(),
        $reason,
    ))->toThrow(
        AnalysisRetryConflictException::class,
        'The analysis dispatch changed',
    );
    expect(fn () => $retryEvent->update([
        'reason' => 'Mutated retry evidence is forbidden.',
    ]))->toThrow(LogicException::class);
    expect(fn () => $newDispatch->delete())->toThrow(LogicException::class);
});

test('automatic retries and the configured run limit cannot be bypassed manually', function () {
    Queue::fake();
    $fixture = analysisOperationsFailedFixture();
    $analysis = $fixture['analysis'];
    $dispatch = $fixture['dispatch'];
    $operator = analysisOperationsSuperAdmin();
    $action = app(RequestManualAnalysisRetry::class);

    config(['analyses.manual_retry_enabled' => false]);
    expect($action->isEligible($analysis))->toBeFalse();
    expect(fn () => $action->request(
        $analysis,
        $operator,
        $dispatch->getKey(),
        (string) Str::uuid(),
        'A disabled production operation must fail closed.',
    ))->toThrow(
        ValidationException::class,
        'Manual analysis retry is disabled',
    );
    config(['analyses.manual_retry_enabled' => true]);

    $analysis->update(['next_retry_at' => now()->addMinute()]);
    $dispatch->update(['available_at' => now()->addMinute()]);

    expect(fn () => $action->request(
        $analysis->fresh(),
        $operator,
        $dispatch->getKey(),
        (string) Str::uuid(),
        'Wait for the already scheduled automatic recovery.',
    ))->toThrow(ValidationException::class);

    $analysis->update(['next_retry_at' => null]);
    $dispatch->update(['available_at' => null]);
    config(['analyses.manual_retry_max_runs' => 1]);

    expect(fn () => $action->request(
        $analysis->fresh(),
        $operator,
        $dispatch->getKey(),
        (string) Str::uuid(),
        'The configured dispatch run ceiling must remain enforced.',
    ))->toThrow(ValidationException::class);
    expect(AnalysisRetryEvent::query()->count())->toBe(0)
        ->and(AnalysisDispatch::query()->count())->toBe(1)
        ->and(SubscriptionUsage::query()->sole()->used)->toBe(1);
});

test('the Filament action delegates one validated retry through the application boundary', function () {
    Queue::fake();
    $fixture = analysisOperationsFailedFixture();
    $operator = analysisOperationsSuperAdmin();

    $this->actingAs($operator);
    Livewire::test(ListAnalysisOperations::class)
        ->assertTableActionVisible(
            'manualRetry',
            $fixture['analysis'],
        )
        ->callTableAction(
            'manualRetry',
            $fixture['analysis'],
            [
                'expected_current_dispatch_id' => (
                    $fixture['dispatch']->getKey()
                ),
                'idempotency_key' => (string) Str::uuid(),
                'reason' => (
                    'The provider recovered and the input was independently reviewed.'
                ),
            ],
        )
        ->assertHasNoTableActionErrors();

    expect(AnalysisRetryEvent::query()->count())->toBe(1)
        ->and(AnalysisDispatch::query()->count())->toBe(2)
        ->and(SubscriptionUsage::query()->sole()->used)->toBe(1);
    Queue::assertPushed(ProcessBuyAnalysis::class, 2);
});

test('the operations query tracks terminal and stale heads without listing healthy analyses', function () {
    Queue::fake();
    $fixture = analysisOperationsFailedFixture();
    $analysis = $fixture['analysis'];
    $dispatch = $fixture['dispatch'];
    $query = app(AnalysisOperationsQuery::class);

    expect($query->count())->toBe(1);

    $analysis->update([
        'status' => AnalysisStatus::Completed,
        'finished_at' => now(),
        'failed_at' => null,
    ]);
    $dispatch->update([
        'status' => AnalysisDispatchStatus::Completed,
        'completed_at' => now(),
        'failed_at' => null,
    ]);
    expect($query->count())->toBe(0);

    $analysis->update([
        'status' => AnalysisStatus::Processing,
        'processing_started_at' => now()->subSeconds(
            (int) config('analyses.processing_timeout_seconds') + 1,
        ),
    ]);
    $dispatch->update(['status' => AnalysisDispatchStatus::Processing]);
    expect($query->count())->toBe(1);

    $analysis->update(['processing_started_at' => now()]);
    expect($query->count())->toBe(0);

    $analysis->update([
        'status' => AnalysisStatus::Queued,
        'processing_started_at' => null,
    ]);
    $dispatch->update([
        'status' => AnalysisDispatchStatus::Dispatching,
        'last_dispatch_attempt_at' => now()->subSeconds(
            (int) config('analyses.dispatch_claim_timeout_seconds') + 1,
        ),
    ]);
    expect($query->count())->toBe(1);
});

test('analysis operations render reviewed evidence but never raw failures or internal hashes', function () {
    Queue::fake();
    $firstSecret = 'first-secret-analysis-provider-token';
    $secondSecret = 'second-secret-analysis-provider-token';
    $fixture = analysisOperationsFailedFixture($firstSecret);
    $operator = analysisOperationsSuperAdmin([
        'email' => 'safe-operator@example.test',
    ]);
    $idempotencyKey = (string) Str::uuid();
    $reason = 'Provider health is restored after reviewing the failed input.';
    $result = app(RequestManualAnalysisRetry::class)->request(
        $fixture['analysis'],
        $operator,
        $fixture['dispatch']->getKey(),
        $idempotencyKey,
        $reason,
    );
    (new ProcessBuyAnalysis(
        $fixture['analysis']->getKey(),
        $result['dispatch']->getKey(),
    ))->failed(new RuntimeException($secondSecret));
    $event = $result['retry_event'];

    $this->actingAs(User::factory()->create())
        ->get(AnalysisOperationResource::getUrl())
        ->assertForbidden();

    $this->actingAs($operator)
        ->get(AnalysisOperationResource::getUrl())
        ->assertOk()
        ->assertSeeText('Analysis Operations Workspace')
        ->assertSeeText('Professional camera for operations revie...')
        ->assertSeeText('Processing runtime failure')
        ->assertSeeText('safe-operator@example.test')
        ->assertSeeText('Provider health is restored after reviewing the failed input...')
        ->assertDontSee($firstSecret)
        ->assertDontSee($secondSecret)
        ->assertDontSee($idempotencyKey)
        ->assertDontSee($event->payload_hash)
        ->assertDontSee($event->previous_error_hash);
});

test('the CLI creates one audited retry and safely replays the exact request', function () {
    Queue::fake();
    $fixture = analysisOperationsFailedFixture();
    $operator = analysisOperationsSuperAdmin([
        'email' => 'cli-analysis-operator@example.test',
    ]);
    $idempotencyKey = (string) Str::uuid();
    $options = [
        '--actor-email' => $operator->email,
        '--expected-dispatch' => $fixture['dispatch']->getKey(),
        '--idempotency' => $idempotencyKey,
        '--reason' => 'CLI operator verified provider recovery and reviewed input.',
    ];

    $this->artisan(
        'analyses:manual-retry',
        ['analysis' => $fixture['analysis']->getKey(), ...$options],
    )->assertSuccessful();
    $this->artisan(
        'analyses:manual-retry',
        ['analysis' => $fixture['analysis']->getKey(), ...$options],
    )->assertSuccessful();

    expect(AnalysisRetryEvent::query()->count())->toBe(1)
        ->and(AnalysisDispatch::query()->count())->toBe(2)
        ->and(PlatformAuditEvent::query()
            ->where('action', 'analysis.manual_retry_requested')
            ->count())->toBe(1)
        ->and(SubscriptionUsage::query()->sole()->used)->toBe(1);
    Queue::assertPushed(ProcessBuyAnalysis::class, 2);
});
