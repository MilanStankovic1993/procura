<?php

namespace App\Analysis\Metrics;

use App\Analysis\Metrics\Contracts\AnalysisPipelineMetricRecorder;
use App\Enums\Analyses\AiAnalysisStatus;
use App\Enums\Analyses\AnalysisPipelineProviderScope;
use App\Models\AiAnalysis;
use App\Models\AnalysisPipelineMetric;

final class DatabaseAnalysisPipelineMetricRecorder implements AnalysisPipelineMetricRecorder
{
    public function record(
        string $aiAnalysisId,
        int $attemptNumber,
        string $pipelineVersion,
        AnalysisPipelineProviderScope $providerScope,
        AnalysisPipelineStageTimer $timer,
    ): void {
        if (config('performance.analysis_pipeline_metrics.enabled') !== true) {
            return;
        }

        $attempt = AiAnalysis::query()
            ->select(['id', 'attempt_number', 'status'])
            ->find($aiAnalysisId);

        if (
            $attempt === null
            || $attempt->attempt_number !== $attemptNumber
            || ! in_array($attempt->status, [
                AiAnalysisStatus::Completed,
                AiAnalysisStatus::Failed,
            ], true)
        ) {
            return;
        }

        AnalysisPipelineMetric::query()->firstOrCreate(
            ['ai_analysis_id' => $attempt->getKey()],
            [
                'attempt_number' => $attemptNumber,
                'pipeline_version' => $pipelineVersion,
                'provider_scope' => $providerScope,
                'attempt_status' => $attempt->status,
                'failed_stage' => $timer->failedStage(),
                ...$timer->durationColumns(),
                'total_microseconds' => $timer->totalMicroseconds(),
                'recorded_at' => now(),
            ],
        );
    }
}
