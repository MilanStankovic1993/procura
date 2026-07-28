<?php

namespace App\Jobs;

use App\Actions\Analyses\RecordAnalysisJobFailure;
use App\Actions\Analyses\RunBuyAnalysis;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessBuyAnalysis implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $analysisId,
        public readonly string $dispatchId,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return array_map('intval', config('analyses.retry_delays_seconds'));
    }

    public function uniqueId(): string
    {
        return $this->dispatchId;
    }

    public function handle(RunBuyAnalysis $runner): void
    {
        $runner->run($this->analysisId, $this->dispatchId);
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception === null) {
            return;
        }

        try {
            app(RecordAnalysisJobFailure::class)->record(
                $this->analysisId,
                $this->dispatchId,
                $exception,
            );
        } catch (Throwable $recordingException) {
            report($recordingException);
        }
    }
}
