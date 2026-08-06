<?php

namespace App\SellPriceIntelligence\Metrics;

use App\Enums\Sell\SellPriceIntelligenceMetricOperation;
use App\Enums\Sell\SellPriceIntelligenceMetricStage;
use App\Models\SellPriceIntelligenceMetric;
use Carbon\CarbonImmutable;

final class SellPriceIntelligenceMetricsReporter
{
    public function __construct(
        private readonly SellPriceIntelligenceMetricsConfiguration $configuration,
    ) {}

    /** @return array<string, mixed> */
    public function report(
        int $windowMinutes,
        int $sampleLimit,
        int $minimumSamples,
        ?int $expectedOperations,
        ?int $expectedScopes,
    ): array {
        $until = CarbonImmutable::now('UTC');
        $since = $until->subMinutes($windowMinutes);
        $rows = SellPriceIntelligenceMetric::query()
            ->where(
                'operation',
                SellPriceIntelligenceMetricOperation::ComparableRecalculation,
            )
            ->where('recorded_at', '>=', $since)
            ->where('recorded_at', '<=', $until)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->limit($sampleLimit + 1)
            ->get();
        $truncated = $rows->count() > $sampleLimit;

        if ($truncated) {
            $rows->pop();
        }

        $budgets = $this->configuration->budgets();
        $stageReports = [];
        $stageBudgetsPassed = true;
        $stagesSufficient = true;

        foreach (SellPriceIntelligenceMetricStage::cases() as $stage) {
            $stageReport = $this->percentiles(
                $rows->pluck($stage->durationColumn())
                    ->filter(static fn (mixed $value): bool => is_int($value))
                    ->values()
                    ->all(),
                $minimumSamples,
                $budgets['stages'][$stage->value],
            );
            $stageReports[$stage->value] = $stageReport;
            $stagesSufficient = $stagesSufficient
                && $stageReport['status'] !== 'insufficient_samples';
            $stageBudgetsPassed = $stageBudgetsPassed
                && $stageReport['status'] === 'pass';
        }

        $totalReport = $this->percentiles(
            $rows->pluck('total_microseconds')->all(),
            $minimumSamples,
            $budgets['maximum_total_p95_milliseconds'],
        );
        $samples = $rows->count();
        $scopeCount = $rows->sum('scope_count');
        $minimumScopes = $this->configuration->minimumScopesPerOperation();
        $singleVersions = $this->versionCounts($rows);
        $operationCountMatches = $expectedOperations !== null
            && $samples === $expectedOperations;
        $scopeCountMatches = $expectedScopes !== null
            && $scopeCount === $expectedScopes;
        $allMultiScope = $samples > 0
            && $rows->every(
                static fn (SellPriceIntelligenceMetric $row): bool => (
                    $row->scope_count >= $minimumScopes
                ),
            );
        $selectionReplays = $rows->sum('selection_replay_count');
        $priceBandReplays = $rows->sum('price_band_replay_count');
        $freshProjectionWrites = $samples > 0
            && $selectionReplays === 0
            && $priceBandReplays === 0;
        $budgetPassed = $stageBudgetsPassed
            && $totalReport['status'] === 'pass';
        $evidenceEligible = app()->environment('staging')
            && ! $truncated
            && $stagesSufficient
            && $totalReport['status'] !== 'insufficient_samples'
            && $operationCountMatches
            && $scopeCountMatches
            && $allMultiScope
            && $freshProjectionWrites
            && $singleVersions['eligible'];

        return [
            'status' => app()->environment('staging')
                ? ($evidenceEligible && $budgetPassed ? 'passed' : 'failed')
                : 'rehearsal',
            'environment' => app()->environment(),
            'release_evidence' => $evidenceEligible && $budgetPassed,
            'budget_version' => $budgets['version'],
            'window' => [
                'minutes' => $windowMinutes,
                'from' => $since->toIso8601String(),
                'to' => $until->toIso8601String(),
            ],
            'sample_limit' => $sampleLimit,
            'minimum_samples' => $minimumSamples,
            'expected_operations' => $expectedOperations,
            'operations' => $samples,
            'operation_count_status' => $operationCountMatches ? 'pass' : 'fail',
            'expected_scopes' => $expectedScopes,
            'scopes' => [
                'total' => $scopeCount,
                'minimum_per_operation' => $minimumScopes,
                'minimum_observed' => $samples === 0 ? null : $rows->min('scope_count'),
                'maximum_observed' => $samples === 0 ? null : $rows->max('scope_count'),
                'count_status' => $scopeCountMatches ? 'pass' : 'fail',
                'multi_scope_status' => $allMultiScope ? 'pass' : 'fail',
            ],
            'truncated' => $truncated,
            'versions' => $singleVersions['counts'],
            'work' => [
                'candidates' => $rows->sum('candidate_count'),
                'selected' => $rows->sum('included_count'),
                'excluded' => $rows->sum('excluded_count'),
                'band_inputs' => $rows->sum('band_input_count'),
                'outliers' => $rows->sum('outlier_count'),
                'selection_replays' => $selectionReplays,
                'price_band_replays' => $priceBandReplays,
                'fresh_projection_status' => $freshProjectionWrites ? 'pass' : 'fail',
            ],
            'stages' => $stageReports,
            'total' => $totalReport,
        ];
    }

    /** @return array{eligible: bool, counts: array<string, array<string, int>>} */
    private function versionCounts($rows): array
    {
        $counts = [
            'metrics' => $rows->countBy('metrics_version')->sortKeys()->all(),
            'selector' => $rows->countBy('selector_version')->sortKeys()->all(),
            'algorithm' => $rows->countBy('algorithm_version')->sortKeys()->all(),
        ];

        return [
            'eligible' => collect($counts)->every(
                static fn (array $versions): bool => count($versions) === 1,
            ),
            'counts' => $counts,
        ];
    }

    /** @param list<int> $microseconds @return array<string, int|float|string|null> */
    private function percentiles(
        array $microseconds,
        int $minimumSamples,
        int $maximumP95Milliseconds,
    ): array {
        sort($microseconds, SORT_NUMERIC);
        $samples = count($microseconds);
        $p95 = $this->percentile($microseconds, 0.95);
        $status = $samples < $minimumSamples
            ? 'insufficient_samples'
            : (($p95 ?? PHP_INT_MAX) <= $maximumP95Milliseconds
                ? 'pass'
                : 'fail');

        return [
            'samples' => $samples,
            'p50_milliseconds' => $this->percentile($microseconds, 0.50),
            'p95_milliseconds' => $p95,
            'p99_milliseconds' => $this->percentile($microseconds, 0.99),
            'maximum_p95_milliseconds' => $maximumP95Milliseconds,
            'status' => $status,
        ];
    }

    /** @param list<int> $values */
    private function percentile(array $values, float $percentile): ?float
    {
        if ($values === []) {
            return null;
        }

        $index = max(0, (int) ceil(count($values) * $percentile) - 1);

        return round($values[$index] / 1000, 3);
    }
}
