<?php

namespace App\Console\Commands;

use App\Operations\Capacity\QueueThroughputConfiguration;
use App\Operations\Capacity\QueueThroughputRunner;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;

final class QueueThroughputCommand extends Command
{
    protected $signature = 'operations:queue-throughput
        {--queue=analyses : Configured worker queue to exercise}
        {--jobs= : Number of bounded synthetic jobs}
        {--timeout= : Maximum seconds to wait for completion}
        {--minimum-throughput= : Optional stricter jobs-per-second target}
        {--maximum-p95-ms= : Optional stricter p95 latency target}
        {--maximum-p99-ms= : Optional stricter p99 latency target}
        {--acknowledge-load : Confirm this command creates real queue traffic}
        {--allow-non-staging : Permit a non-production local rehearsal}
        {--json : Emit exactly one machine-readable JSON document}';

    protected $description = 'Measure bounded queue throughput and latency percentiles';

    /**
     * @throws JsonException
     */
    public function handle(
        QueueThroughputConfiguration $configuration,
        QueueThroughputRunner $runner,
    ): int {
        if (app()->environment('production')) {
            return $this->failBeforeMeasurement(
                'production_forbidden',
                'Queue throughput workloads are permanently forbidden in production.',
            );
        }

        if (
            ! app()->environment('staging')
            && ! (bool) $this->option('allow-non-staging')
        ) {
            return $this->failBeforeMeasurement(
                'staging_environment_required',
                'Queue throughput evidence must run in staging.',
            );
        }

        if (! (bool) $this->option('acknowledge-load')) {
            return $this->failBeforeMeasurement(
                'load_acknowledgement_required',
                'Explicit acknowledgement of the synthetic queue load is required.',
            );
        }

        try {
            $queue = $this->queue($configuration);
            $jobs = $configuration->jobs($this->option('jobs'));
            $timeout = $configuration->timeoutSeconds(
                $this->option('timeout'),
            );
            $budget = $configuration->budget(
                $this->option('minimum-throughput'),
                $this->option('maximum-p95-ms'),
                $this->option('maximum-p99-ms'),
            );

            if (app()->environment('staging')) {
                $configuration->assertStagingInfrastructure();
            }
        } catch (RuntimeException) {
            return $this->failBeforeMeasurement(
                'invalid_configuration',
                'Queue throughput options or infrastructure are invalid.',
            );
        }

        $report = $runner->run(
            queue: $queue,
            jobs: $jobs,
            timeoutSeconds: $timeout,
            budget: $budget,
        );

        if ((bool) $this->option('json')) {
            $this->line(json_encode(
                $report->toArray(),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));
        } else {
            $this->table(
                [
                    'Queue',
                    'Status',
                    'Completed',
                    'Jobs/s',
                    'p50 ms',
                    'p95 ms',
                    'p99 ms',
                ],
                [[
                    $report->queue,
                    $report->toArray()['status'],
                    "{$report->completedJobs}/{$report->expectedJobs}",
                    round($report->throughputPerSecond, 3),
                    $report->latencyMilliseconds['p50'] ?? '-',
                    $report->latencyMilliseconds['p95'] ?? '-',
                    $report->latencyMilliseconds['p99'] ?? '-',
                ]],
            );

            if ($report->passed()) {
                $this->components->info(
                    'The queue throughput workload passed.',
                );
            } else {
                $this->components->error(
                    'The queue throughput workload failed.',
                );
            }
        }

        return $report->passed() ? self::SUCCESS : self::FAILURE;
    }

    private function queue(
        QueueThroughputConfiguration $configuration,
    ): string {
        $queue = $this->option('queue');

        if (
            ! is_string($queue)
            || preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $queue) !== 1
        ) {
            throw new RuntimeException(
                'The queue throughput queue option is invalid.',
            );
        }

        $configuration->assertQueue($queue);

        return $queue;
    }

    private function failBeforeMeasurement(
        string $code,
        string $message,
    ): int {
        if ((bool) $this->option('json')) {
            $this->line(json_encode(
                [
                    'status' => 'failed',
                    'error_code' => $code,
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));
        } else {
            $this->components->error($message);
        }

        return self::FAILURE;
    }
}
