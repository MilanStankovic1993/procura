<?php

namespace App\Actions\Analyses;

use App\Enums\Analyses\AnalysisDispatchStatus;
use App\Enums\Analyses\AnalysisStatus;
use App\Jobs\ProcessBuyAnalysis;
use App\Models\Analysis;
use App\Models\AnalysisDispatch;
use Illuminate\Support\Facades\DB;
use Throwable;

class DispatchAnalysis
{
    public function dispatch(AnalysisDispatch|string $dispatch): AnalysisDispatch
    {
        $dispatchId = $dispatch instanceof AnalysisDispatch
            ? $dispatch->getKey()
            : $dispatch;
        $claimed = DB::transaction(function () use ($dispatchId): ?AnalysisDispatch {
            $record = AnalysisDispatch::query()->lockForUpdate()->findOrFail($dispatchId);
            $processingLeaseExpired = false;

            if ($record->status === AnalysisDispatchStatus::Processing) {
                $analysis = Analysis::query()
                    ->lockForUpdate()
                    ->findOrFail($record->analysis_id);
                $processingLeaseExpired = $analysis->status === AnalysisStatus::Processing
                    && $analysis->processing_started_at?->lte(
                        now()->subSeconds(
                            (int) config('analyses.processing_timeout_seconds'),
                        ),
                    );
            }

            if (
                in_array($record->status, [
                    AnalysisDispatchStatus::Dispatched,
                    AnalysisDispatchStatus::Completed,
                ], true)
                || (
                    $record->status === AnalysisDispatchStatus::Processing
                    && ! $processingLeaseExpired
                )
            ) {
                return null;
            }

            $claimExpired = $record->status === AnalysisDispatchStatus::Dispatching
                && $record->last_dispatch_attempt_at?->lte(
                    now()->subSeconds((int) config('analyses.dispatch_claim_timeout_seconds')),
                );

            if ($record->status === AnalysisDispatchStatus::Dispatching && ! $claimExpired) {
                return null;
            }

            if ($record->available_at?->isFuture()) {
                return null;
            }

            $record->update([
                'status' => AnalysisDispatchStatus::Dispatching,
                'dispatch_attempts' => $record->dispatch_attempts + 1,
                'last_dispatch_attempt_at' => now(),
                'last_error' => null,
            ]);

            return $record->fresh();
        }, attempts: 3);

        if ($claimed === null) {
            return AnalysisDispatch::query()->findOrFail($dispatchId);
        }

        try {
            ProcessBuyAnalysis::dispatch(
                $claimed->analysis_id,
                $claimed->getKey(),
            )->onQueue($claimed->queue_name);

            AnalysisDispatch::query()
                ->whereKey($claimed->getKey())
                ->where('status', AnalysisDispatchStatus::Dispatching)
                ->update([
                    'status' => AnalysisDispatchStatus::Dispatched,
                    'dispatched_at' => now(),
                    'failed_at' => null,
                    'available_at' => null,
                    'updated_at' => now(),
                ]);
        } catch (Throwable $exception) {
            $delay = (int) (config('analyses.retry_delays_seconds')[0] ?? 10);
            AnalysisDispatch::query()->whereKey($claimed->getKey())->update([
                'status' => AnalysisDispatchStatus::Failed,
                'available_at' => now()->addSeconds($delay),
                'failed_at' => now(),
                'last_error' => str($exception->getMessage())->limit(10000)->toString(),
                'updated_at' => now(),
            ]);

            report($exception);
        }

        return AnalysisDispatch::query()->findOrFail($dispatchId);
    }
}
