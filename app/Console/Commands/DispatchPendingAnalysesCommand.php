<?php

namespace App\Console\Commands;

use App\Actions\Analyses\DispatchAnalysis;
use App\Enums\Analyses\AnalysisDispatchStatus;
use App\Enums\Analyses\AnalysisStatus;
use App\Models\AnalysisDispatch;
use Illuminate\Console\Command;

class DispatchPendingAnalysesCommand extends Command
{
    protected $signature = 'analyses:dispatch-pending {--limit=100}';

    protected $description = 'Dispatch pending analysis outbox records without duplicating queued work.';

    public function handle(DispatchAnalysis $dispatcher): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $staleBefore = now()->subSeconds(
            (int) config('analyses.dispatch_claim_timeout_seconds'),
        );
        $processingStaleBefore = now()->subSeconds(
            (int) config('analyses.processing_timeout_seconds'),
        );
        $records = AnalysisDispatch::query()
            ->where(static function ($query) use ($staleBefore, $processingStaleBefore): void {
                $query->where('status', AnalysisDispatchStatus::Pending)
                    ->orWhere(static function ($query): void {
                        $query->where('status', AnalysisDispatchStatus::Failed)
                            ->whereNull('dispatched_at');
                    })
                    ->orWhere(static function ($query) use ($staleBefore): void {
                        $query->where('status', AnalysisDispatchStatus::Dispatching)
                            ->where('last_dispatch_attempt_at', '<=', $staleBefore);
                    })
                    ->orWhere(static function ($query) use ($processingStaleBefore): void {
                        $query->where('status', AnalysisDispatchStatus::Processing)
                            ->whereHas('analysis', static function ($query) use (
                                $processingStaleBefore,
                            ): void {
                                $query->where('status', AnalysisStatus::Processing)
                                    ->where(
                                        'processing_started_at',
                                        '<=',
                                        $processingStaleBefore,
                                    );
                            });
                    });
            })
            ->where(static function ($query): void {
                $query->whereNull('available_at')->orWhere('available_at', '<=', now());
            })
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        foreach ($records as $record) {
            $dispatcher->dispatch($record);
        }

        $this->info("Processed {$records->count()} pending analysis dispatch records.");

        return self::SUCCESS;
    }
}
