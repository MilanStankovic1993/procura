<?php

use App\Actions\Listings\CreateListing;
use App\Actions\Markets\SyncMarketReferenceData;
use App\Enums\Analyses\AnalysisStatus;
use App\Models\MarketplaceSource;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Operations\Capacity\CapacityBaseline;
use App\Operations\Dashboard\PlatformOverviewMetrics;
use App\Operations\OperationsConfiguration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config()->set('operations.dashboard_metrics.cache_store', 'array');
    Cache::store('array')->flush();
    app(SyncMarketReferenceData::class)->sync();
});

/**
 * @return array{organization: Organization, analysis_count: int}
 */
function capacityAnalysisFixture(int $analysisCount = 2000): array
{
    $user = User::factory()->create();
    $organization = Organization::factory()->create([
        'name' => 'Capacity Baseline Workspace',
    ]);
    OrganizationMembership::factory()->owner()->create([
        'organization_id' => $organization,
        'user_id' => $user,
    ]);
    $user->forceFill([
        'current_organization_id' => $organization->getKey(),
    ])->save();
    $source = MarketplaceSource::factory()->create();
    $listing = app(CreateListing::class)->create(
        $organization,
        $user,
        $source,
        [
            'source_url' => 'https://capacity.example/listing',
            'external_id' => 'capacity-listing',
            'marketplace_name' => 'Capacity Market',
            'title' => 'Capacity baseline product',
            'description' => 'Synthetic non-production capacity evidence.',
            'asking_price_minor' => 12500,
            'currency_code' => 'EUR',
            'seller_information' => 'Synthetic fixture',
            'location' => 'Vienna',
            'source_country_code' => 'AT',
            'target_country_code' => 'DE',
            'status' => 'active',
            'notes' => null,
        ],
    );
    $snapshot = $listing->snapshots()->firstOrFail();
    $baseTime = now('UTC')->startOfSecond();
    $rows = [];

    for ($index = 0; $index < $analysisCount; $index++) {
        $failed = $index % 10 === 0;
        $status = $failed
            ? AnalysisStatus::Failed
            : (
                $index % 2 === 0
                    ? AnalysisStatus::Completed
                    : AnalysisStatus::Queued
            );
        $timestamp = $baseTime
            ->copy()
            ->subSeconds($analysisCount - $index)
            ->toDateTimeString();

        $rows[] = [
            'id' => (string) Str::ulid(),
            'organization_id' => $organization->getKey(),
            'listing_id' => $listing->getKey(),
            'listing_snapshot_id' => $snapshot->getKey(),
            'requested_by_user_id' => $user->getKey(),
            'analysis_type' => 'buy',
            'status' => $status->value,
            'source_country_code' => 'AT',
            'target_country_code' => 'DE',
            'pipeline_version' => 'capacity-fixture:v1',
            'request_payload' => json_encode(
                ['fixture' => $index],
                JSON_THROW_ON_ERROR,
            ),
            'request_hash' => hash(
                'sha256',
                "capacity-analysis-{$index}",
            ),
            'result_payload' => $status === AnalysisStatus::Completed
                ? json_encode(['status' => 'complete'], JSON_THROW_ON_ERROR)
                : null,
            'processing_attempts' => $failed ? 3 : 1,
            'submitted_at' => $timestamp,
            'processing_started_at' => null,
            'finished_at' => $status === AnalysisStatus::Completed
                ? $timestamp
                : null,
            'failed_at' => $failed ? $timestamp : null,
            'archived_at' => null,
            'next_retry_at' => null,
            'last_error_code' => $failed
                ? 'CapacityFixtureFailure'
                : null,
            'last_error_message' => $failed
                ? 'Synthetic bounded failure.'
                : null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];

        if (count($rows) === 25) {
            DB::table('analyses')->insert($rows);
            $rows = [];
        }
    }

    if ($rows !== []) {
        DB::table('analyses')->insert($rows);
    }

    return [
        'organization' => $organization,
        'analysis_count' => $analysisCount,
    ];
}

test('critical query counts stay constant with two thousand tenant analyses', function () {
    $fixture = capacityAnalysisFixture();

    $report = app(CapacityBaseline::class)->measure(
        organization: $fixture['organization'],
        enforceDuration: false,
    );

    expect($fixture['analysis_count'])->toBe(2000)
        ->and($report->passed())->toBeTrue()
        ->and(
            $report->probes['platform_dashboard_cold']->queryCount,
        )->toBe(11)
        ->and(
            $report->probes['analysis_operations_count']->queryCount,
        )->toBe(1)
        ->and(
            $report->probes['analysis_operations_count']->resultCount,
        )->toBe(200)
        ->and(
            $report->probes['tenant_analysis_index']->queryCount,
        )->toBe(2)
        ->and(
            $report->probes['tenant_analysis_index']->resultCount,
        )->toBe(50);
})->group('capacity');

test('the shared dashboard snapshot removes repeated aggregate queries', function () {
    capacityAnalysisFixture(200);
    $metrics = app(PlatformOverviewMetrics::class);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $cold = $metrics->snapshot();
    $coldQueries = DB::getQueryLog();
    DB::flushQueryLog();
    $warm = $metrics->snapshot();
    $warmQueries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($cold)->toBe($warm)
        ->and($cold['users'])->toBeGreaterThanOrEqual(1)
        ->and($cold['analysis_operations'])->toBe(20)
        ->and($coldQueries)->toHaveCount(11)
        ->and($warmQueries)->toHaveCount(0);
})->group('capacity');

test('a corrupt dashboard cache payload is replaced by a valid snapshot', function () {
    capacityAnalysisFixture(100);
    $metrics = app(PlatformOverviewMetrics::class);
    $cacheKey = app(
        OperationsConfiguration::class,
    )->dashboardMetricsCacheKey();

    Cache::store('array')->put(
        $cacheKey,
        ['users' => 'invalid'],
        30,
    );

    DB::flushQueryLog();
    DB::enableQueryLog();
    $recovered = $metrics->snapshot();
    $recoveryQueries = DB::getQueryLog();
    DB::flushQueryLog();
    $cached = $metrics->snapshot();
    $cachedQueries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($recovered)->toBe($cached)
        ->and($recovered['analysis_operations'])->toBe(10)
        ->and($recoveryQueries)->toHaveCount(11)
        ->and($cachedQueries)->toHaveCount(0)
        ->and(Cache::store('array')->get($cacheKey))->toBe($recovered);
})->group('capacity');

test('the capacity command emits one strict machine-readable report', function () {
    $fixture = capacityAnalysisFixture(200);

    $exitCode = Artisan::call('operations:capacity-baseline', [
        '--organization' => $fixture['organization']->getKey(),
        '--json' => true,
    ]);
    $payload = json_decode(
        trim(Artisan::output()),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($exitCode)->toBe(0)
        ->and($payload['status'])->toBe('passed')
        ->and($payload['probes']['platform_dashboard_cold']['query_count'])
        ->toBe(11)
        ->and($payload['probes']['analysis_operations_count']['query_count'])
        ->toBe(1)
        ->and($payload['probes']['tenant_analysis_index']['query_count'])
        ->toBe(2)
        ->and($payload['probes']['tenant_analysis_index']['result_count'])
        ->toBe(50);
})->group('capacity');

test('invalid tenant input fails before any capacity measurement', function () {
    $exitCode = Artisan::call('operations:capacity-baseline', [
        '--organization' => 'not-a-valid-ulid',
        '--json' => true,
    ]);
    $payload = json_decode(
        trim(Artisan::output()),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($exitCode)->toBe(1)
        ->and($payload)->toBe([
            'status' => 'failed',
            'error_code' => 'invalid_organization',
        ]);
})->group('capacity');

test('production measurement requires an explicit read-only acknowledgement', function () {
    $application = app();
    $originalEnvironment = $application->environment();
    $application->detectEnvironment(
        fn (): string => 'production',
    );

    try {
        $exitCode = Artisan::call('operations:capacity-baseline', [
            '--json' => true,
        ]);
        $payload = json_decode(
            trim(Artisan::output()),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    } finally {
        $application->detectEnvironment(
            fn (): string => $originalEnvironment,
        );
    }

    expect($exitCode)->toBe(1)
        ->and($payload)->toBe([
            'status' => 'failed',
            'error_code' => 'production_confirmation_required',
        ]);
})->group('capacity');

test('versioned query budgets fail closed on regression', function () {
    config()->set(
        'performance.capacity_baseline.probes.platform_dashboard_cold.maximum_queries',
        10,
    );

    $report = app(CapacityBaseline::class)->measure(
        organization: null,
        enforceDuration: false,
    );

    expect($report->passed())->toBeFalse()
        ->and($report->probes['platform_dashboard_cold']->status)
        ->toBe('failed')
        ->and($report->probes['tenant_analysis_index']->status)
        ->toBe('skipped');
})->group('capacity');
