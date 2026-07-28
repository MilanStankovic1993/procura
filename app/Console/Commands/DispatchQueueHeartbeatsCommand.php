<?php

namespace App\Console\Commands;

use App\Jobs\Operations\RecordQueueHeartbeat;
use App\Operations\OperationsConfiguration;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class DispatchQueueHeartbeatsCommand extends Command
{
    protected $signature = 'operations:dispatch-queue-heartbeats';

    protected $description = 'Dispatch one readiness heartbeat to every configured queue';

    public function handle(OperationsConfiguration $configuration): int
    {
        if (! $configuration->queueHeartbeatsEnabled()) {
            $this->components->info(
                'Queue heartbeat monitoring is disabled.',
            );

            return self::SUCCESS;
        }

        $dispatchedAt = CarbonImmutable::now('UTC')->toIso8601String();
        $queues = $configuration->queueNames();

        foreach ($queues as $queue) {
            RecordQueueHeartbeat::dispatch(
                queueName: $queue,
                dispatchedAt: $dispatchedAt,
            );
        }

        $this->components->info(sprintf(
            'Dispatched %d queue readiness heartbeats.',
            count($queues),
        ));

        return self::SUCCESS;
    }
}
