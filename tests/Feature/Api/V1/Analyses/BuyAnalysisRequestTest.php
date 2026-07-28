<?php

use App\Actions\Analyses\CreateBuyAnalysisDraft;
use App\Actions\Analyses\RunBuyAnalysis;
use App\Actions\Analyses\SubmitAnalysis;
use App\Actions\Listings\CreateListing;
use App\Actions\Markets\SyncMarketReferenceData;
use App\Analysis\Contracts\ListingAiAnalyzer;
use App\Analysis\Data\AiAnalysisData;
use App\Analysis\Data\AnalysisInputData;
use App\Enums\Analyses\AiAnalysisStatus;
use App\Enums\Analyses\AiValidationStatus;
use App\Enums\Analyses\AnalysisDispatchStatus;
use App\Enums\Analyses\AnalysisStatus;
use App\Enums\Listings\ListingImageKind;
use App\Enums\Organizations\OrganizationRole;
use App\Enums\Subscriptions\FeatureCode;
use App\Jobs\Monitoring\MatchListingSnapshot;
use App\Jobs\ProcessBuyAnalysis;
use App\Models\AiAnalysis;
use App\Models\Analysis;
use App\Models\AnalysisDispatch;
use App\Models\Brand;
use App\Models\Listing;
use App\Models\ListingImage;
use App\Models\MarketplaceSource;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProductAlias;
use App\Models\ProductCategory;
use App\Models\ProductMatch;
use App\Models\ProductModel;
use App\Models\SubscriptionUsage;
use App\Models\User;
use App\Subscriptions\SubscriptionUsageService;
use Database\Seeders\PlanSeeder;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    app(SyncMarketReferenceData::class)->sync();
    $this->seed(PlanSeeder::class);
    analysisRequestCatalog();
});

function analysisRequestCatalog(): ProductModel
{
    $category = ProductCategory::query()->create([
        'name' => 'Cordless Drills',
        'slug' => 'cordless-drills',
    ]);
    $brand = Brand::query()->create(['name' => 'Bosch Professional']);
    $model = ProductModel::query()->create([
        'brand_id' => $brand->getKey(),
        'product_category_id' => $category->getKey(),
        'name' => 'Bosch Professional Cordless Drill',
        'model_number' => 'QA-DRILL-18V',
        'canonical_key' => 'bosch-professional:qa-drill-18v',
    ]);
    ProductAlias::query()->create([
        'product_model_id' => $model->getKey(),
        'alias' => 'Bosch Professional cordless drill',
        'source' => 'golden_test',
    ]);

    return $model;
}

function analysisRequestWorkspace(
    OrganizationRole $role = OrganizationRole::Owner,
): array {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    OrganizationMembership::factory()->create([
        'organization_id' => $organization,
        'user_id' => $user,
        'role' => $role,
    ]);
    $user->update(['current_organization_id' => $organization->getKey()]);

    return [$user, $organization];
}

function analysisRequestListing(
    User $user,
    Organization $organization,
    bool $withEvidence = false,
    array $overrides = [],
): Listing {
    $listing = app(CreateListing::class)->create(
        $organization,
        $user,
        MarketplaceSource::query()->where('key', 'manual')->firstOrFail(),
        [
            'source_url' => 'https://market.example/listings/analysis-drill',
            'external_id' => 'analysis-drill',
            'marketplace_name' => 'Analysis Market',
            'title' => 'Bosch Professional cordless drill',
            'description' => 'Two batteries, charger, and case.',
            'asking_price_minor' => 12999,
            'currency_code' => 'EUR',
            'seller_information' => 'Private seller.',
            'location' => 'Vienna',
            'source_country_code' => 'AT',
            'target_country_code' => 'DE',
            'status' => 'active',
            'notes' => null,
            ...$overrides,
        ],
    );

    if ($withEvidence) {
        ListingImage::query()->create([
            'listing_id' => $listing->getKey(),
            'uploaded_by_user_id' => $user->getKey(),
            'kind' => ListingImageKind::Product,
            'disk' => 'local',
            'path' => "testing/{$listing->getKey()}/drill.png",
            'client_filename' => 'drill.png',
            'mime_type' => 'image/png',
            'extension' => 'png',
            'size_bytes' => 2048,
            'width' => 800,
            'height' => 600,
            'checksum_sha256' => str_repeat('a', 64),
            'position' => 1,
        ]);
    }

    return $listing;
}

test('an analyst creates an immutable draft from an exact listing snapshot without using quota', function () {
    Queue::fake();
    [$analyst, $organization] = analysisRequestWorkspace(OrganizationRole::Analyst);
    $listing = analysisRequestListing($analyst, $organization, withEvidence: true);

    $response = $this->actingAs($analyst)
        ->postJson(route('api.v1.buy-analyses.store'), [
            'listing_id' => $listing->getKey(),
            'target_country_code' => 'FR',
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.source_country_code', 'AT')
        ->assertJsonPath('data.target_country_code', 'FR')
        ->assertJsonPath('data.request_payload.market_scope.target_country_code', 'FR')
        ->assertJsonPath('data.request_payload.listing.snapshot_sequence', 1)
        ->assertJsonPath('data.request_payload.evidence.0.checksum_sha256', str_repeat('a', 64));

    $analysis = Analysis::query()->findOrFail($response->json('data.id'));
    $duplicate = app(CreateBuyAnalysisDraft::class)->create(
        $organization,
        $analyst,
        $listing->getKey(),
        'FR',
    );

    expect($analysis->request_hash)->toHaveLength(64)
        ->and($duplicate->is($analysis))->toBeTrue()
        ->and(Analysis::query()->count())->toBe(1)
        ->and($analysis->listing_snapshot_id)->toBe($listing->snapshots()->value('id'))
        ->and(SubscriptionUsage::query()->count())->toBe(0)
        ->and(AnalysisDispatch::query()->count())->toBe(0);
    Queue::assertPushed(MatchListingSnapshot::class, 1);
    Queue::assertNotPushed(ProcessBuyAnalysis::class);

    expect(fn () => $analysis->update(['target_country_code' => 'IT']))
        ->toThrow(LogicException::class);
});

test('submitting a draft consumes quota and dispatches exactly once across duplicate requests', function () {
    Queue::fake();
    [$analyst, $organization] = analysisRequestWorkspace(OrganizationRole::Analyst);
    $listing = analysisRequestListing($analyst, $organization, withEvidence: true);
    $analysis = app(CreateBuyAnalysisDraft::class)->create(
        $organization,
        $analyst,
        $listing->getKey(),
        'DE',
    );

    $this->actingAs($analyst)
        ->postJson(route('api.v1.analyses.submit', $analysis))
        ->assertAccepted()
        ->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.current_dispatch.status', 'dispatched');
    $this->actingAs($analyst)
        ->postJson(route('api.v1.analyses.submit', $analysis))
        ->assertAccepted();

    expect(SubscriptionUsage::query()->value('used'))->toBe(1)
        ->and(DB::table('subscription_usage_events')->count())->toBe(1)
        ->and(AnalysisDispatch::query()->count())->toBe(1)
        ->and(AnalysisDispatch::query()->value('dispatch_attempts'))->toBe(1);
    Queue::assertPushed(ProcessBuyAnalysis::class, 1);
});

test('quota exhaustion leaves the analysis as a draft with no dispatch record', function () {
    Queue::fake();
    [$owner, $organization] = analysisRequestWorkspace();
    $listing = analysisRequestListing($owner, $organization);
    $usage = app(SubscriptionUsageService::class);

    for ($index = 1; $index <= 5; $index++) {
        $usage->consume(
            $organization,
            FeatureCode::MonthlyAnalyses,
            1,
            "preexisting-analysis:{$index}",
        );
    }

    $analysis = app(CreateBuyAnalysisDraft::class)->create(
        $organization,
        $owner,
        $listing->getKey(),
        'DE',
    );

    $this->actingAs($owner)
        ->postJson(route('api.v1.analyses.submit', $analysis))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'analysis_quota_exceeded')
        ->assertJsonPath('meta.limit', 5)
        ->assertJsonPath('meta.used', 5);

    expect($analysis->fresh()->status)->toBe(AnalysisStatus::Draft)
        ->and(AnalysisDispatch::query()->count())->toBe(0)
        ->and(SubscriptionUsage::query()->value('used'))->toBe(5);
    Queue::assertPushed(MatchListingSnapshot::class, 1);
    Queue::assertNotPushed(ProcessBuyAnalysis::class);
});

test('analysis routes never reveal or mutate another tenant records', function () {
    Queue::fake();
    [$owner, $organization] = analysisRequestWorkspace();
    [$outsider] = analysisRequestWorkspace();
    $listing = analysisRequestListing($owner, $organization);
    $analysis = app(CreateBuyAnalysisDraft::class)->create(
        $organization,
        $owner,
        $listing->getKey(),
        'DE',
    );

    $this->actingAs($outsider)
        ->postJson(route('api.v1.buy-analyses.store'), [
            'listing_id' => $listing->getKey(),
            'target_country_code' => 'DE',
        ])
        ->assertNotFound();
    $this->actingAs($outsider)
        ->getJson(route('api.v1.analyses.show', $analysis))
        ->assertNotFound();
    $this->actingAs($outsider)
        ->postJson(route('api.v1.analyses.submit', $analysis))
        ->assertNotFound();
    $this->actingAs($outsider)
        ->getJson(route('api.v1.analyses.index', ['listing_id' => $listing->getKey()]))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('viewers can inspect analyses while only analysts and managers can create or submit', function () {
    Queue::fake();
    [$owner, $organization] = analysisRequestWorkspace();
    $viewer = User::factory()->create();
    OrganizationMembership::factory()->viewer()->create([
        'organization_id' => $organization,
        'user_id' => $viewer,
    ]);
    $viewer->update(['current_organization_id' => $organization->getKey()]);
    $listing = analysisRequestListing($owner, $organization);
    $analysis = app(CreateBuyAnalysisDraft::class)->create(
        $organization,
        $owner,
        $listing->getKey(),
        'DE',
    );

    $this->actingAs($viewer)
        ->getJson(route('api.v1.analyses.show', $analysis))
        ->assertOk();
    $this->actingAs($viewer)
        ->postJson(route('api.v1.buy-analyses.store'), [
            'listing_id' => $listing->getKey(),
            'target_country_code' => 'DE',
        ])
        ->assertForbidden();
    $this->actingAs($viewer)
        ->postJson(route('api.v1.analyses.submit', $analysis))
        ->assertForbidden();
});

test('the deterministic provider preserves a missing-comparables boundary and duplicate jobs are inert', function () {
    Queue::fake();
    [$analyst, $organization] = analysisRequestWorkspace(OrganizationRole::Analyst);
    $listing = analysisRequestListing($analyst, $organization, withEvidence: true);
    $analysis = app(CreateBuyAnalysisDraft::class)->create(
        $organization,
        $analyst,
        $listing->getKey(),
        'DE',
    );
    $analysis = app(SubmitAnalysis::class)->submit($organization, $analyst, $analysis->getKey());
    $dispatch = $analysis->currentDispatch()->firstOrFail();

    app(RunBuyAnalysis::class)->run($analysis->getKey(), $dispatch->getKey());
    app(RunBuyAnalysis::class)->run($analysis->getKey(), $dispatch->getKey());

    $analysis->refresh();
    $attempt = AiAnalysis::query()->firstOrFail();

    expect($analysis->status)->toBe(AnalysisStatus::NeedsInput)
        ->and($analysis->processing_attempts)->toBe(1)
        ->and($analysis->result_payload['normalized_listing']['asking_price_minor'])->toBe(12999)
        ->and($analysis->result_payload['completed_steps'])->toContain('product_matching')
        ->and($analysis->result_payload['completed_steps'])->toContain('comparable_selection')
        ->and(in_array('product_matching', $analysis->result_payload['pending_steps'], true))
        ->toBeFalse()
        ->and(in_array('comparable_selection', $analysis->result_payload['pending_steps'], true))
        ->toBeFalse()
        ->and($analysis->result_payload['needs_input'])->toContain('no_comparable_records')
        ->and($analysis->result_payload['pending_steps'])->toContain('price_estimation')
        ->and($dispatch->fresh()->status)->toBe(AnalysisDispatchStatus::Completed)
        ->and($attempt->status)->toBe(AiAnalysisStatus::Completed)
        ->and($attempt->validation_status)->toBe(AiValidationStatus::Valid)
        ->and($attempt->confidence_basis_points)->toBe(9000)
        ->and(AiAnalysis::query()->count())->toBe(1)
        ->and(ProductMatch::query()->count())->toBe(1)
        ->and(ProductMatch::query()->firstOrFail()->status->value)->toBe('matched');
});

test('missing price currency and evidence produce a structured needs input state', function () {
    Queue::fake();
    [$owner, $organization] = analysisRequestWorkspace();
    $listing = analysisRequestListing($owner, $organization, overrides: [
        'asking_price_minor' => null,
        'currency_code' => null,
    ]);
    $analysis = app(CreateBuyAnalysisDraft::class)->create(
        $organization,
        $owner,
        $listing->getKey(),
        'DE',
    );
    $analysis = app(SubmitAnalysis::class)->submit($organization, $owner, $analysis->getKey());

    app(RunBuyAnalysis::class)->run(
        $analysis->getKey(),
        $analysis->currentDispatch()->valueOrFail('id'),
    );

    $analysis->refresh();
    expect($analysis->status)->toBe(AnalysisStatus::NeedsInput)
        ->and($analysis->result_payload['needs_input'])
        ->toBe([
            'price_missing',
            'currency_unclear',
            'images_insufficient',
            'no_comparable_records',
        ]);
});

test('provider failures record bounded retry metadata and one observable attempt per run', function () {
    Queue::fake();
    [$owner, $organization] = analysisRequestWorkspace();
    $listing = analysisRequestListing($owner, $organization, withEvidence: true);
    $analysis = app(CreateBuyAnalysisDraft::class)->create(
        $organization,
        $owner,
        $listing->getKey(),
        'DE',
    );
    $analysis = app(SubmitAnalysis::class)->submit($organization, $owner, $analysis->getKey());
    $dispatch = $analysis->currentDispatch()->firstOrFail();
    app()->instance(ListingAiAnalyzer::class, new class implements ListingAiAnalyzer
    {
        public function analyze(AnalysisInputData $input): AiAnalysisData
        {
            throw new RuntimeException('Deterministic provider failure.');
        }
    });
    $runner = app(RunBuyAnalysis::class);

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        expect(fn () => $runner->run($analysis->getKey(), $dispatch->getKey()))
            ->toThrow(RuntimeException::class, 'Deterministic provider failure.');
    }

    $runner->run($analysis->getKey(), $dispatch->getKey());
    $analysis->refresh();

    expect($analysis->status)->toBe(AnalysisStatus::Failed)
        ->and($analysis->processing_attempts)->toBe(3)
        ->and($analysis->next_retry_at)->toBeNull()
        ->and($analysis->last_error_code)->toBe('RuntimeException')
        ->and($dispatch->fresh()->status)->toBe(AnalysisDispatchStatus::Failed)
        ->and(AiAnalysis::query()->count())->toBe(3)
        ->and(AiAnalysis::query()->where('status', AiAnalysisStatus::Failed)->count())->toBe(3);
});

test('an exhausted queue job records an unexpected terminal failure without corrupting completed work', function () {
    Queue::fake();
    [$owner, $organization] = analysisRequestWorkspace();
    $listing = analysisRequestListing($owner, $organization, withEvidence: true);
    $analysis = app(CreateBuyAnalysisDraft::class)->create(
        $organization,
        $owner,
        $listing->getKey(),
        'DE',
    );
    $analysis = app(SubmitAnalysis::class)->submit($organization, $owner, $analysis->getKey());
    $dispatch = $analysis->currentDispatch()->firstOrFail();
    $job = new ProcessBuyAnalysis($analysis->getKey(), $dispatch->getKey());

    $job->failed(new RuntimeException('Queue worker exhausted all attempts.'));
    $analysis->refresh();
    $dispatch->refresh();

    expect($analysis->status)->toBe(AnalysisStatus::Failed)
        ->and($analysis->next_retry_at)->toBeNull()
        ->and($analysis->last_error_code)->toBe('RuntimeException')
        ->and($dispatch->status)->toBe(AnalysisDispatchStatus::Failed)
        ->and($dispatch->available_at)->toBeNull()
        ->and(AiAnalysis::query()->count())->toBe(0);

    $analysis->update([
        'status' => AnalysisStatus::Completed,
        'finished_at' => now(),
        'last_error_code' => null,
        'last_error_message' => null,
    ]);
    $dispatch->update([
        'status' => AnalysisDispatchStatus::Completed,
        'completed_at' => now(),
    ]);

    $job->failed(new RuntimeException('Late duplicate failure callback.'));
    $analysis->refresh();
    $dispatch->refresh();

    expect($analysis->status)->toBe(AnalysisStatus::Completed)
        ->and($analysis->last_error_code)->toBeNull()
        ->and($dispatch->status)->toBe(AnalysisDispatchStatus::Completed);
});

test('the recovery command redispatches a stale processing lease and preserves attempt history', function () {
    Queue::fake();
    [$owner, $organization] = analysisRequestWorkspace();
    $listing = analysisRequestListing($owner, $organization, withEvidence: true);
    $analysis = app(CreateBuyAnalysisDraft::class)->create(
        $organization,
        $owner,
        $listing->getKey(),
        'DE',
    );
    $analysis = app(SubmitAnalysis::class)->submit($organization, $owner, $analysis->getKey());
    $dispatch = $analysis->currentDispatch()->firstOrFail();
    $staleStartedAt = now()->subSeconds(
        (int) config('analyses.processing_timeout_seconds') + 1,
    );
    $analysis->update([
        'status' => AnalysisStatus::Processing,
        'processing_attempts' => 1,
        'processing_started_at' => $staleStartedAt,
    ]);
    $dispatch->update(['status' => AnalysisDispatchStatus::Processing]);
    $abandonedAttempt = AiAnalysis::query()->create([
        'organization_id' => $organization->getKey(),
        'analysis_id' => $analysis->getKey(),
        'attempt_number' => 1,
        'status' => AiAnalysisStatus::Processing,
        'provider' => config('analyses.provider'),
        'model' => config('analyses.fake_model'),
        'prompt_version' => config('analyses.prompt_version'),
        'input_hash' => $analysis->request_hash,
        'input_snapshot' => $analysis->request_payload,
        'validation_status' => AiValidationStatus::Pending,
        'started_at' => $staleStartedAt,
    ]);
    app(UniqueLock::class)->release(
        new ProcessBuyAnalysis($analysis->getKey(), $dispatch->getKey()),
    );

    $this->artisan('analyses:dispatch-pending')
        ->expectsOutput('Processed 1 pending analysis dispatch records.')
        ->assertSuccessful();

    Queue::assertPushed(ProcessBuyAnalysis::class, 2);
    $dispatch->refresh();
    expect($dispatch->status)->toBe(AnalysisDispatchStatus::Dispatched)
        ->and($dispatch->dispatch_attempts)->toBe(2);

    app(RunBuyAnalysis::class)->run($analysis->getKey(), $dispatch->getKey());
    $analysis->refresh();
    $abandonedAttempt->refresh();

    expect($analysis->status)->toBe(AnalysisStatus::NeedsInput)
        ->and($analysis->processing_attempts)->toBe(2)
        ->and($abandonedAttempt->status)->toBe(AiAnalysisStatus::Failed)
        ->and($abandonedAttempt->validation_status)->toBe(AiValidationStatus::Invalid)
        ->and($abandonedAttempt->error)
        ->toBe('The previous processing lease expired before completion.')
        ->and(AiAnalysis::query()->count())->toBe(2);
});
