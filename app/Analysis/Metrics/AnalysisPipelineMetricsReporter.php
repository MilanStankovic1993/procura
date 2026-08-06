<?php

namespace App\Analysis\Metrics;

use App\Enums\Analyses\AiAnalysisStatus;
use App\Enums\Analyses\AnalysisPipelineProviderScope;
use App\Enums\Analyses\AnalysisPipelineStage;
use App\Models\AnalysisPipelineMetric;
use Carbon\CarbonImmutable;

final class AnalysisPipelineMetricsReporter
{
    public function __construct(
        private readonly AnalysisPipelineMetricsConfiguration $configuration,
    ) {}

    /** @return array<string, mixed> */
    public function report(
        int $windowMinutes,
        int $sampleLimit,
        int $minimumSamples,
        ?int $expectedSamples,
    ): array {
        $until = CarbonImmutable::now('UTC');
        $since = $until->subMinutes($windowMinutes);
        $rows = AnalysisPipelineMetric::query()
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
        $samples = $rows->count();
        $failed = $rows->where(
            'attempt_status',
            AiAnalysisStatus::Failed,
        )->count();
        $failureRate = $samples === 0
            ? 0
            : (int) round(($failed * 10_000) / $samples);
        $stageReports = [];
        $stagesSufficient = true;
        $stageBudgetsPassed = true;

        foreach (AnalysisPipelineStage::cases() as $stage) {
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
            $rows->pluck('total_microseconds')
                ->filter(static fn (mixed $value): bool => is_int($value))
                ->values()
                ->all(),
            $minimumSamples,
            $budgets['maximum_total_p95_milliseconds'],
        );
        $providerScopes = [
            AnalysisPipelineProviderScope::Rehearsal->value => $rows->where(
                'provider_scope',
                AnalysisPipelineProviderScope::Rehearsal,
            )->count(),
            AnalysisPipelineProviderScope::ProductionShaped->value => $rows->where(
                'provider_scope',
                AnalysisPipelineProviderScope::ProductionShaped,
            )->count(),
        ];
        $pipelineVersions = $rows
            ->countBy('pipeline_version')
            ->sortKeys()
            ->all();
        $failedStages = [];

        foreach (AnalysisPipelineStage::cases() as $stage) {
            $failedStages[$stage->value] = $rows->where(
                'failed_stage',
                $stage,
            )->count();
        }
        $singlePipelineVersion = count($pipelineVersions) === 1;
        $sampleCountMatches = $expectedSamples !== null
            && $samples === $expectedSamples;
        $productionShaped = $samples > 0
            && $providerScopes[AnalysisPipelineProviderScope::Rehearsal->value] === 0;
        $failureBudgetPassed = $failureRate
            <= $budgets['maximum_failure_rate_basis_points'];
        $budgetPassed = $stageBudgetsPassed
            && $totalReport['status'] === 'pass'
            && $failureBudgetPassed;
        $evidenceEligible = app()->environment('staging')
            && ! $truncated
            && $stagesSufficient
            && $totalReport['status'] !== 'insufficient_samples'
            && $productionShaped
            && $singlePipelineVersion
            && $sampleCountMatches;

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
            'minimum_samples_per_stage' => $minimumSamples,
            'expected_samples' => $expectedSamples,
            'samples' => $samples,
            'sample_count_status' => $sampleCountMatches ? 'pass' : 'fail',
            'truncated' => $truncated,
            'pipeline_versions' => $pipelineVersions,
            'provider_scopes' => $providerScopes,
            'attempts' => [
                'completed' => $samples - $failed,
                'failed' => $failed,
                'failure_rate_basis_points' => $failureRate,
                'maximum_failure_rate_basis_points' => $budgets[
                    'maximum_failure_rate_basis_points'
                ],
                'status' => $failureBudgetPassed ? 'pass' : 'fail',
            ],
            'failed_stages' => $failedStages,
            'stages' => $stageReports,
            'total' => $totalReport,
        ];
    }

    /**
     * @param  list<int>  $microseconds
     * @return array{
     *     samples: int,
     *     p50_milliseconds: float|null,
     *     p95_milliseconds: float|null,
     *     p99_milliseconds: float|null,
     *     maximum_p95_milliseconds: int,
     *     status: string
     * }
     */
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
