<?php

namespace App\Analysis\Metrics\Contracts;

use App\Analysis\Metrics\AnalysisPipelineStageTimer;
use App\Enums\Analyses\AnalysisPipelineProviderScope;

interface AnalysisPipelineMetricRecorder
{
    public function record(
        string $aiAnalysisId,
        int $attemptNumber,
        string $pipelineVersion,
        AnalysisPipelineProviderScope $providerScope,
        AnalysisPipelineStageTimer $timer,
    ): void;
}
