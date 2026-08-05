<?php

namespace App\Jobs\Operations;

use App\Operations\Capacity\QueueThroughputStore;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class RecordQueueThroughputProbe implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 10;

    public function __construct(
        public readonly string $runId,
        public readonly int $sequence,
        public readonly string $dispatchedAt,
        string $queueName,
    ) {
        $this->onQueue($queueName);
    }

    public function handle(QueueThroughputStore $store): void
    {
        $store->record(
            runId: $this->runId,
            sequence: $this->sequence,
            dispatchedAt: CarbonImmutable::parse(
                $this->dispatchedAt,
                'UTC',
            ),
        );
    }
}
