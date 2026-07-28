<?php

namespace App\Operations\Capacity;

use App\Analysis\Operations\AnalysisOperationsQuery;
use App\Analysis\Queries\AnalysisIndexQuery;
use App\Models\Organization;
use App\Operations\Capacity\Data\CapacityBaselineReport;
use App\Operations\Capacity\Data\CapacityProbeResult;
use App\Operations\Dashboard\PlatformOverviewMetrics;
use Carbon\CarbonImmutable;

final class CapacityBaseline
{
    public function __construct(
        private readonly QueryProbeRunner $runner,
        private readonly CapacityConfiguration $configuration,
        private readonly PlatformOverviewMetrics $dashboard,
        private readonly AnalysisOperationsQuery $analysisOperations,
        private readonly AnalysisIndexQuery $analysisIndex,
    ) {}

    public function measure(
        ?Organization $organization,
        bool $enforceDuration,
    ): CapacityBaselineReport {
        $dashboardBudget = $this->configuration->budget(
            'platform_dashboard_cold',
        );
        $operationsBudget = $this->configuration->budget(
            'analysis_operations_count',
        );
        $indexBudget = $this->configuration->budget(
            'tenant_analysis_index',
        );

        $probes = [
            'platform_dashboard_cold' => $this->runner->run(
                name: 'platform_dashboard_cold',
                probe: fn (): int => count(
                    $this->dashboard->uncached(),
                ),
                budget: $dashboardBudget,
                enforceDuration: $enforceDuration,
            ),
            'analysis_operations_count' => $this->runner->run(
                name: 'analysis_operations_count',
                probe: fn (): int => $this->analysisOperations->count(),
                budget: $operationsBudget,
                enforceDuration: $enforceDuration,
            ),
            'tenant_analysis_index' => $organization === null
                ? CapacityProbeResult::skipped(
                    'tenant_analysis_index',
                    $indexBudget,
                )
                : $this->runner->run(
                    name: 'tenant_analysis_index',
                    probe: fn (): int => $this->analysisIndex
                        ->build($organization)
                        ->limit(
                            $this->configuration->tenantPageSize(),
                        )
                        ->get()
                        ->count(),
                    budget: $indexBudget,
                    enforceDuration: $enforceDuration,
                ),
        ];

        return new CapacityBaselineReport(
            environment: app()->environment(),
            measuredAt: CarbonImmutable::now('UTC'),
            probes: $probes,
        );
    }
}
