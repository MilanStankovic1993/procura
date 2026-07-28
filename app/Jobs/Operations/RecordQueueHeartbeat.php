<?php

namespace App\Jobs\Operations;

use App\Operations\QueueHeartbeatStore;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class RecordQueueHeartbeat implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 10;

    public function __construct(
        public readonly string $queueName,
        public readonly string $dispatchedAt,
    ) {
        $this->onQueue($queueName);
    }

    public function handle(QueueHeartbeatStore $heartbeats): void
    {
        $heartbeats->record(
            queue: $this->queueName,
            dispatchedAt: CarbonImmutable::parse(
                $this->dispatchedAt,
                'UTC',
            ),
        );
    }
}
