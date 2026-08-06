<?php

use App\Enums\Sell\SellPriceIntelligenceMetricOperation;
use App\Enums\Sell\SellPriceIntelligenceMetricStage;
use App\Models\SellPriceIntelligenceMetric;
use App\SellPriceIntelligence\Metrics\Contracts\SellPriceIntelligenceMetricRecorder;
use App\SellPriceIntelligence\Metrics\DatabaseSellPriceIntelligenceMetricRecorder;
use App\SellPriceIntelligence\Metrics\SellPriceIntelligenceMetrics;
use App\SellPriceIntelligence\Metrics\SellPriceIntelligenceMetricTimer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    config([
        'performance.sell_price_intelligence_metrics.enabled' => true,
        'performance.sell_price_intelligence_metrics.default_minimum_samples' => 1,
        'performance.sell_price_intelligence_metrics.retention_days' => 30,
    ]);
});

function createSellPriceIntelligenceMetric(
    int $durationMicroseconds = 12_000,
    int $scopeCount = 2,
    int $selectionReplays = 0,
    int $priceBandReplays = 0,
    ?DateTimeInterface $recordedAt = null,
    string $metricsVersion = 'sell-price-intelligence-metrics:v1',
): SellPriceIntelligenceMetric {
    return SellPriceIntelligenceMetric::query()->create([
        'operation' => SellPriceIntelligenceMetricOperation::ComparableRecalculation,
        'metrics_version' => $metricsVersion,
        'selector_version' => 'deterministic-sell-comparable-selector:v2',
        'algorithm_version' => 'deterministic-sell-price-bands:v2',
        'scope_count' => $scopeCount,
        'candidate_count' => $scopeCount * 5,
        'included_count' => $scopeCount * 3,
        'excluded_count' => $scopeCount * 2,
        'band_input_count' => $scopeCount * 3,
        'outlier_count' => 0,
        'selection_replay_count' => $selectionReplays,
        'price_band_replay_count' => $priceBandReplays,
        'scope_discovery_microseconds' => $durationMicroseconds,
        'comparable_selection_microseconds' => $durationMicroseconds,
        'selection_persistence_microseconds' => $durationMicroseconds,
        'price_band_estimation_microseconds' => $durationMicroseconds,
        'price_band_persistence_microseconds' => $durationMicroseconds,
        'total_microseconds' => $durationMicroseconds * 5,
        'recorded_at' => $recordedAt ?? now(),
    ]);
}

function runSellMetricCommand(string $environment, array $options): array
{
    $application = app();
    $originalEnvironment = $application->environment();
    $application->detectEnvironment(fn (): string => $environment);

    try {
        $exitCode = Artisan::call(
            'operations:sell-price-intelligence-stage-metrics',
            [...$options, '--json' => true],
        );
        $output = trim(Artisan::output());
    } finally {
        $application->detectEnvironment(fn (): string => $originalEnvironment);
    }

    return [
        $exitCode,
        $output,
        json_decode($output, true, flags: JSON_THROW_ON_ERROR),
    ];
}

test('staging emits passing bounded identifier-free multi-scope evidence', function () {
    $metric = createSellPriceIntelligenceMetric();

    [$exitCode, $output, $report] = runSellMetricCommand('staging', [
        '--window' => '60',
        '--limit' => '10',
        '--minimum-samples' => '1',
        '--expected-operations' => '1',
        '--expected-scopes' => '2',
    ]);

    expect($exitCode)->toBe(0)
        ->and($report['status'])->toBe('passed')
        ->and($report['release_evidence'])->toBeTrue()
        ->and($report['operations'])->toBe(1)
        ->and($report['scopes']['total'])->toBe(2)
        ->and($report['scopes']['multi_scope_status'])->toBe('pass')
        ->and($report['work']['fresh_projection_status'])->toBe('pass')
        ->and($report['budget_version'])
        ->toBe('sell-price-intelligence-stage-budget:v1')
        ->and($report['stages']['comparable_selection']['p95_milliseconds'])
        ->toBe(12)
        ->and($report['total']['p99_milliseconds'])->toBe(60)
        ->and(substr_count($output, PHP_EOL))->toBe(0)
        ->and($output)->not->toContain($metric->getKey());
})->group('capacity');

test('single-scope or replayed projections cannot become release evidence', function () {
    createSellPriceIntelligenceMetric(
        scopeCount: 1,
        selectionReplays: 1,
        priceBandReplays: 1,
    );

    [$exitCode, , $report] = runSellMetricCommand('staging', [
        '--limit' => '10',
        '--minimum-samples' => '1',
        '--expected-operations' => '1',
        '--expected-scopes' => '2',
    ]);

    expect($exitCode)->toBe(1)
        ->and($report['status'])->toBe('failed')
        ->and($report['release_evidence'])->toBeFalse()
        ->and($report['scopes']['multi_scope_status'])->toBe('fail')
        ->and($report['scopes']['count_status'])->toBe('fail')
        ->and($report['work']['fresh_projection_status'])->toBe('fail');
})->group('capacity');

test('truncated or mixed-version samples cannot become release evidence', function () {
    createSellPriceIntelligenceMetric();
    createSellPriceIntelligenceMetric(
        metricsVersion: 'sell-price-intelligence-metrics:v2',
    );

    [$truncatedExit, , $truncated] = runSellMetricCommand('staging', [
        '--limit' => '1',
        '--minimum-samples' => '1',
        '--expected-operations' => '1',
        '--expected-scopes' => '2',
    ]);
    [$mixedExit, , $mixed] = runSellMetricCommand('staging', [
        '--limit' => '2',
        '--minimum-samples' => '1',
        '--expected-operations' => '2',
        '--expected-scopes' => '4',
    ]);

    expect($truncatedExit)->toBe(1)
        ->and($truncated['truncated'])->toBeTrue()
        ->and($mixedExit)->toBe(1)
        ->and($mixed['release_evidence'])->toBeFalse()
        ->and($mixed['versions']['metrics'])->toHaveCount(2);
})->group('capacity');

test('a versioned p95 regression fails staging evidence', function () {
    config()->set(
        'performance.sell_price_intelligence_metrics.budgets.maximum_total_p95_milliseconds',
        1,
    );
    createSellPriceIntelligenceMetric();

    [$exitCode, , $report] = runSellMetricCommand('staging', [
        '--limit' => '10',
        '--minimum-samples' => '1',
        '--expected-operations' => '1',
        '--expected-scopes' => '2',
    ]);

    expect($exitCode)->toBe(1)
        ->and($report['status'])->toBe('failed')
        ->and($report['release_evidence'])->toBeFalse()
        ->and($report['total']['status'])->toBe('fail')
        ->and($report['total']['maximum_p95_milliseconds'])->toBe(1);
})->group('capacity');

test('production is forbidden and staging requires exact reviewed counts', function () {
    createSellPriceIntelligenceMetric();

    [$productionExit, , $production] = runSellMetricCommand('production', [
        '--allow-local-rehearsal' => true,
    ]);
    [$stagingExit, , $staging] = runSellMetricCommand('staging', [
        '--minimum-samples' => '1',
    ]);

    expect($productionExit)->toBe(1)
        ->and($production['error_code'])->toBe('production_forbidden')
        ->and($stagingExit)->toBe(1)
        ->and($staging['error_code'])->toBe('invalid_configuration');
})->group('capacity');

test('the timer records aggregate stage and scope work without business identifiers', function () {
    $timer = SellPriceIntelligenceMetricTimer::start(
        SellPriceIntelligenceMetricOperation::ComparableRecalculation,
    );

    foreach (SellPriceIntelligenceMetricStage::cases() as $stage) {
        $timer->measure($stage, static fn (): true => true);
    }

    $timer->observeScope(5, 3, 2, 3, 0, false, false);
    $timer->observeScope(4, 3, 1, 3, 1, true, true);
    $timer->finish();
    app(DatabaseSellPriceIntelligenceMetricRecorder::class)->record($timer);

    $metric = SellPriceIntelligenceMetric::query()->firstOrFail();

    expect($metric->scope_count)->toBe(2)
        ->and($metric->candidate_count)->toBe(9)
        ->and($metric->included_count)->toBe(6)
        ->and($metric->selection_replay_count)->toBe(1)
        ->and($metric->price_band_replay_count)->toBe(1)
        ->and($metric->total_microseconds)->toBeGreaterThan(0)
        ->and($metric->getAttributes())->not->toHaveKeys([
            'organization_id',
            'user_id',
            'owned_product_id',
            'country_code',
            'currency_code',
            'input_hash',
            'payload',
        ]);
});

test('a metric recorder outage cannot escape the after-commit boundary', function () {
    $timer = SellPriceIntelligenceMetricTimer::start(
        SellPriceIntelligenceMetricOperation::ComparableRecalculation,
    );
    $timer->observeScope(1, 1, 0, 1, 0, false, false);
    $timer->finish();
    $recorder = new class implements SellPriceIntelligenceMetricRecorder
    {
        public function record(SellPriceIntelligenceMetricTimer $timer): void
        {
            throw new RuntimeException('Synthetic metric storage outage.');
        }
    };
    DB::shouldReceive('afterCommit')
        ->once()
        ->andReturnUsing(static function (Closure $callback): void {
            $callback();
        });

    expect(fn () => (new SellPriceIntelligenceMetrics($recorder))
        ->recordAfterCommit($timer))->not->toThrow(RuntimeException::class);
});

test('retention is bounded and metric rows are otherwise immutable', function () {
    $oldest = createSellPriceIntelligenceMetric(
        recordedAt: now()->subDays(32),
    );
    $older = createSellPriceIntelligenceMetric(
        recordedAt: now()->subDays(31),
    );
    $fresh = createSellPriceIntelligenceMetric();

    $exitCode = Artisan::call(
        'operations:purge-sell-price-intelligence-metrics',
        ['--limit' => '1'],
    );

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())
        ->toContain('Purged 1 expired Sell price-intelligence metric rows.')
        ->and(SellPriceIntelligenceMetric::query()->find($oldest->getKey()))
        ->toBeNull()
        ->and(SellPriceIntelligenceMetric::query()->find($older->getKey()))
        ->not->toBeNull()
        ->and(SellPriceIntelligenceMetric::query()->find($fresh->getKey()))
        ->not->toBeNull()
        ->and(fn () => $fresh->update(['total_microseconds' => 1]))
        ->toThrow(LogicException::class, 'metrics are immutable')
        ->and(fn () => $fresh->delete())
        ->toThrow(LogicException::class, 'retention purge');
});
