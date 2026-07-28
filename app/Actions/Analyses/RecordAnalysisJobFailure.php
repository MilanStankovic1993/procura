<?php

namespace App\Actions\Analyses;

use App\Enums\Analyses\AiAnalysisStatus;
use App\Enums\Analyses\AiValidationStatus;
use App\Enums\Analyses\AnalysisDispatchStatus;
use App\Enums\Analyses\AnalysisStatus;
use App\Models\AiAnalysis;
use App\Models\Analysis;
use App\Models\AnalysisDispatch;
use Illuminate\Support\Facades\DB;
use Throwable;

class RecordAnalysisJobFailure
{
    public function record(
        string $analysisId,
        string $dispatchId,
        Throwable $exception,
    ): void {
        DB::transaction(function () use ($analysisId, $dispatchId, $exception): void {
            $analysis = Analysis::query()->lockForUpdate()->find($analysisId);
            $dispatch = AnalysisDispatch::query()->lockForUpdate()->find($dispatchId);

            if (
                $analysis === null
                || $dispatch === null
                || $dispatch->analysis_id !== $analysis->getKey()
                || $analysis->status->isTerminal()
                || $dispatch->status === AnalysisDispatchStatus::Completed
            ) {
                return;
            }

            $message = str($exception->getMessage())->limit(10000)->toString();
            $errorCode = class_basename($exception);
            $failedAt = now();

            AiAnalysis::query()
                ->where('analysis_id', $analysis->getKey())
                ->where('status', AiAnalysisStatus::Processing)
                ->update([
                    'status' => AiAnalysisStatus::Failed,
                    'validation_status' => AiValidationStatus::Invalid,
                    'completed_at' => $failedAt,
                    'error' => $message,
                    'updated_at' => $failedAt,
                ]);

            $analysis->update([
                'status' => AnalysisStatus::Failed,
                'failed_at' => $failedAt,
                'next_retry_at' => null,
                'last_error_code' => $errorCode,
                'last_error_message' => $message,
            ]);
            $dispatch->update([
                'status' => AnalysisDispatchStatus::Failed,
                'available_at' => null,
                'failed_at' => $failedAt,
                'last_error' => $message,
            ]);
        }, attempts: 3);
    }
}
