<?php

namespace App\Analysis\Metrics;

use Illuminate\Support\Facades\DB;

final class PurgeAnalysisPipelineMetrics
{
    public function __construct(
        private readonly AnalysisPipelineMetricsConfiguration $configuration,
    ) {}

    public function execute(int $limit): int
    {
        $cutoff = now()->subDays($this->configuration->retentionDays());
        $identifiers = DB::table('analysis_pipeline_metrics')
            ->where('recorded_at', '<', $cutoff)
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->all();

        if ($identifiers === []) {
            return 0;
        }

        return DB::table('analysis_pipeline_metrics')
            ->whereIn('id', $identifiers)
            ->delete();
    }
}
