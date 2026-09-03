<?php

use App\Actions\Analyses\CreateBuyAnalysisDraft;
use App\Actions\Listings\CreateListing;
use App\Actions\Markets\SyncMarketReferenceData;
use App\Analysis\Monitoring\AnalysisProviderMonitor;
use App\Enums\Analyses\AiAnalysisStatus;
use App\Enums\Analyses\AiValidationStatus;
use App\Enums\Analyses\AnalysisProviderBudgetScope;
use App\Enums\Analyses\AnalysisProviderCircuitState;
use App\Enums\Analyses\AnalysisProviderUsageStatus;
use App\Enums\Organizations\OrganizationRole;
use App\Models\AiAnalysis;
use App\Models\Analysis;
use App\Models\AnalysisProviderBudgetPeriod;
use App\Models\AnalysisProviderCircuit;
use App\Models\AnalysisProviderUsage;
use App\Models\MarketplaceSource;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config([
        'analyses.provider_monitoring.enabled' => true,
        'analyses.provider_monitoring.stale_reservation_minutes' => 15,
        'analyses.provider_monitoring.recent_window_minutes' => 60,
        'analyses.provider_monitoring.uncertain_outcome_limit' => 3,
        'analyses.provider_monitoring.rate_limit_limit' => 1,
        'analyses.provider_monitoring.server_error_limit' => 2,
        'analyses.provider_monitoring.budget_utilization_basis_points' => 7500,
        'analyses.provider_governance.task_max_cost_minor' => 10,
        'analyses.provider_governance.global_monthly_budget_minor' => 100,
        'analyses.provider_governance.organization_monthly_budget_minor' => 100,
        'analyses.provider_governance.user_monthly_budget_minor' => 100,
    ]);
    app(SyncMarketReferenceData::class)->sync();
});

/** @return array{User, Organization, Analysis} */
function providerMonitoringAnalysis(): array
{
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    OrganizationMembership::factory()->create([
        'organization_id' => $organization,
        'user_id' => $user,
        'role' => OrganizationRole::Owner,
    ]);
    $listing = app(CreateListing::class)->create(
        $organization,
        $user,
        MarketplaceSource::query()->where('key', 'manual')->firstOrFail(),
        [
            'source_url' => 'https://market.example/monitoring-fixture',
            'external_id' => (string) Str::uuid(),
            'marketplace_name' => 'Private monitoring fixture',
            'title' => 'Synthetic provider monitor fixture',
            'description' => null,
            'asking_price_minor' => 10000,
            'currency_code' => 'EUR',
            'seller_information' => null,
            'location' => null,
            'source_country_code' => 'DE',
            'target_country_code' => 'AT',
            'status' => 'active',
            'notes' => null,
        ],
    );
    $analysis = app(CreateBuyAnalysisDraft::class)->create(
        $organization,
        $user,
        $listing->getKey(),
        'AT',
    );

    return [$user, $organization, $analysis];
}

function providerMonitoringUsage(
    User $user,
    Organization $organization,
    Analysis $analysis,
    int $attempt,
    AnalysisProviderUsageStatus $status,
    ?string $failureCode,
    bool $stale = false,
): AnalysisProviderUsage {
    $startedAt = $stale ? now()->subMinutes(20) : now()->subMinutes(5);
    $settled = $status !== AnalysisProviderUsageStatus::Reserved;
    $aiAnalysis = AiAnalysis::query()->create([
        'organization_id' => $organization->getKey(),
        'analysis_id' => $analysis->getKey(),
        'attempt_number' => $attempt,
        'status' => $settled ? AiAnalysisStatus::Failed : AiAnalysisStatus::Processing,
        'provider' => 'private-provider-marker',
        'model' => 'private-model-marker',
        'prompt_version' => 'monitoring-fixture:v1',
        'input_hash' => hash('sha256', "monitoring-fixture-{$attempt}"),
        'input_snapshot' => ['private' => 'input-marker'],
        'validation_status' => $settled
            ? AiValidationStatus::Invalid
            : AiValidationStatus::Pending,
        'started_at' => $startedAt,
        'completed_at' => $settled ? now()->subMinutes(4) : null,
        'error' => $failureCode,
    ]);

    return AnalysisProviderUsage::query()->create([
        'organization_id' => $organization->getKey(),
        'user_id' => $user->getKey(),
        'analysis_id' => $analysis->getKey(),
        'ai_analysis_id' => $aiAnalysis->getKey(),
        'provider' => 'private-provider-marker',
        'model' => 'private-model-marker',
        'period_start' => now('UTC')->startOfMonth()->toDateString(),
        'status' => $status,
        'reserved_cost_minor' => 5,
        'actual_cost_minor' => $settled ? 5 : null,
        'cost_currency' => 'USD',
        'failure_code' => $failureCode,
        'started_at' => $startedAt,
        'completed_at' => $settled ? now()->subMinutes(4) : null,
    ]);
}

test('provider monitoring classifies bounded aggregate signals in one query', function () {
    [$user, $organization, $analysis] = providerMonitoringAnalysis();
    providerMonitoringUsage(
        $user,
        $organization,
        $analysis,
        1,
        AnalysisProviderUsageStatus::Reserved,
        null,
        stale: true,
    );

    foreach ([
        'analysis_provider_rate_limited',
        'analysis_provider_server_error',
        'analysis_provider_server_error',
        'analysis_provider_cost_exceeded_reservation',
    ] as $index => $failureCode) {
        providerMonitoringUsage(
            $user,
            $organization,
            $analysis,
            $index + 2,
            AnalysisProviderUsageStatus::Uncertain,
            $failureCode,
        );
    }

    foreach ([
        AnalysisProviderCircuitState::Open,
        AnalysisProviderCircuitState::HalfOpen,
    ] as $index => $state) {
        AnalysisProviderCircuit::query()->create([
            'provider' => "private-provider-marker-{$index}",
            'model' => 'private-model-marker',
            'state' => $state,
            'consecutive_failures' => 3,
        ]);
    }

    foreach ([
        [AnalysisProviderBudgetScope::Global, 'global', 80],
        [AnalysisProviderBudgetScope::Organization, $organization->getKey(), 75],
        [AnalysisProviderBudgetScope::User, (string) $user->getKey(), 74],
    ] as [$scope, $scopeId, $cost]) {
        AnalysisProviderBudgetPeriod::query()->create([
            'period_start' => now('UTC')->startOfMonth()->toDateString(),
            'scope_type' => $scope,
            'scope_id' => $scopeId,
            'reserved_cost_minor' => 0,
            'consumed_cost_minor' => $cost,
        ]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $report = app(AnalysisProviderMonitor::class)->inspect();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    $encoded = json_encode($report->operatorPayload(), JSON_THROW_ON_ERROR);

    expect($report->counts)->toBe([
        'open_circuits' => 1,
        'half_open_circuits' => 1,
        'stale_reservations' => 1,
        'uncertain_outcomes_recent' => 4,
        'rate_limits_recent' => 1,
        'server_errors_recent' => 2,
        'cost_overruns_recent' => 1,
        'budget_scopes_near_limit' => 2,
    ])->and($report->attentionCount())->toBe(8)
        ->and($report->status())->toBe('attention_required')
        ->and($queries)->toHaveCount(1)
        ->and($encoded)->not->toContain($organization->getKey())
        ->not->toContain('private-provider-marker')
        ->not->toContain('private-model-marker')
        ->not->toContain('input-marker');
});

test('provider monitoring command has safe disabled report and alerting modes', function () {
    $clearExit = Artisan::call('analyses:provider-status', [
        '--json' => true,
        '--fail-on-attention' => true,
    ]);
    $clearPayload = json_decode(
        trim(Artisan::output()),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    config()->set('analyses.provider_monitoring.enabled', false);
    $disabledExit = Artisan::call('analyses:provider-status', [
        '--json' => true,
        '--fail-on-attention' => true,
    ]);
    $disabledPayload = json_decode(
        trim(Artisan::output()),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    config()->set('analyses.provider_monitoring.enabled', true);
    AnalysisProviderCircuit::query()->create([
        'provider' => 'private-provider-marker',
        'model' => 'private-model-marker',
        'state' => AnalysisProviderCircuitState::Open,
        'consecutive_failures' => 3,
    ]);
    $attentionExit = Artisan::call('analyses:provider-status', [
        '--json' => true,
        '--fail-on-attention' => true,
    ]);
    $attentionPayload = json_decode(
        trim(Artisan::output()),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $reportOnlyExit = Artisan::call('analyses:provider-status', [
        '--json' => true,
    ]);

    expect($clearExit)->toBe(0)
        ->and($clearPayload['status'])->toBe('clear')
        ->and($disabledExit)->toBe(1)
        ->and($disabledPayload)->toBe(['status' => 'not_monitored'])
        ->and($attentionExit)->toBe(1)
        ->and($attentionPayload['status'])->toBe('attention_required')
        ->and($attentionPayload['counts']['open_circuits'])->toBe(1)
        ->and($reportOnlyExit)->toBe(0)
        ->and(json_encode($attentionPayload, JSON_THROW_ON_ERROR))
        ->not->toContain('private-provider-marker')
        ->not->toContain('private-model-marker');
});

test('provider monitoring rejects unsafe threshold configuration', function () {
    config()->set(
        'analyses.provider_monitoring.budget_utilization_basis_points',
        0,
    );

    expect(fn () => app(AnalysisProviderMonitor::class)->inspect())
        ->toThrow(
            RuntimeException::class,
            'The analysis provider monitoring budget_utilization_basis_points setting is invalid.',
        );
});
