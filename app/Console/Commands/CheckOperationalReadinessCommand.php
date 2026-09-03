<?php

namespace App\Console\Commands;

use App\Operations\OperationalReadiness;
use Illuminate\Console\Command;
use JsonException;

final class CheckOperationalReadinessCommand extends Command
{
    protected $signature = 'operations:readiness
        {--require-queue-heartbeats : Fail unless queue heartbeat monitoring is enabled and fresh}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Check database, cache, and queue processing readiness';

    /**
     * @throws JsonException
     */
    public function handle(OperationalReadiness $readiness): int
    {
        $report = $readiness->inspect(
            requireQueueHeartbeats: (
                (bool) $this->option('require-queue-heartbeats')
            ),
        );

        if ((bool) $this->option('json')) {
            $this->line(json_encode(
                $report->operatorPayload(),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));
        } else {
            $this->table(
                ['Dependency', 'Status'],
                collect($report->checks)
                    ->map(
                        fn (string $status, string $dependency): array => [
                            $dependency,
                            $status,
                        ],
                    )
                    ->values()
                    ->all(),
            );

            if ($report->queueChecks !== []) {
                $this->table(
                    ['Queue', 'Status', 'Age (s)', 'Latency (ms)'],
                    collect($report->queueChecks)
                        ->map(
                            fn (
                                array $check,
                                string $queue,
                            ): array => [
                                $queue,
                                $check['status'],
                                $check['age_seconds'] ?? '-',
                                $check['latency_milliseconds'] ?? '-',
                            ],
                        )
                        ->values()
                        ->all(),
                );
            }
        }

        if (! (bool) $this->option('json')) {
            if (! $report->ready) {
                $this->components->error(
                    'Procura is not operationally ready.',
                );
            } else {
                $this->components->info(
                    'Procura is operationally ready.',
                );
            }
        }

        return $report->ready ? self::SUCCESS : self::FAILURE;
    }
}
