<?php

namespace App\Operations\Capacity;

use App\Jobs\Operations\RecordQueueThroughputProbe;
use App\Operations\Capacity\Data\QueueThroughputReceipt;
use App\Operations\Capacity\Data\QueueThroughputReport;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Str;
use Throwable;

final class QueueThroughputRunner
{
    public function __construct(
        private readonly Dispatcher $dispatcher,
        private readonly QueueThroughputStore $store,
        private readonly QueueThroughputConfiguration $configuration,
    ) {}

    /**
     * @param  array{
     *     minimum_throughput_per_second: float,
     *     maximum_p95_latency_milliseconds: int,
     *     maximum_p99_latency_milliseconds: int
     * }  $budget
     */
    public function run(
        string $queue,
        int $jobs,
        int $timeoutSeconds,
        array $budget,
    ): QueueThroughputReport {
        $this->assertWorkload($queue, $jobs, $timeoutSeconds, $budget);
        $runId = (string) Str::ulid();
        $startedAt = CarbonImmutable::now('UTC');
        $startedNanoseconds = hrtime(true);
        $dispatchMilliseconds = 0.0;
        $receipts = [];
        $errorCode = null;
        $started = false;

        try {
            $this->store->start($runId, $jobs);
            $started = true;
            $dispatchStartedNanoseconds = hrtime(true);

            for ($sequence = 1; $sequence <= $jobs; $sequence++) {
                $this->dispatcher->dispatch(
                    new RecordQueueThroughputProbe(
                        runId: $runId,
                        sequence: $sequence,
                        dispatchedAt: CarbonImmutable::now('UTC')
                            ->toISOString(),
                        queueName: $queue,
                    ),
                );
            }

            $dispatchMilliseconds = (
                hrtime(true) - $dispatchStartedNanoseconds
            ) / 1_000_000;
            $deadlineNanoseconds = $startedNanoseconds
                + ($timeoutSeconds * 1_000_000_000);

            do {
                $receipts = $this->store->collect($runId, $jobs);

                if (count($receipts) === $jobs) {
                    break;
                }

                usleep(
                    $this->configuration->pollIntervalMilliseconds()
                    * 1000,
                );
            } while (hrtime(true) < $deadlineNanoseconds);
        } catch (Throwable $exception) {
            $errorCode = class_basename($exception);
        }

        $finishedAt = CarbonImmutable::now('UTC');
        $measurementMilliseconds = max(
            (hrtime(true) - $startedNanoseconds) / 1_000_000,
            0.001,
        );
        $completedJobs = count($receipts);
        $throughputPerSecond = $completedJobs === 0
            ? 0.0
            : $completedJobs / ($measurementMilliseconds / 1000);
        $latencies = array_values(array_map(
            static fn (QueueThroughputReceipt $receipt): int => (
                $receipt->latencyMilliseconds
            ),
            $receipts,
        ));
        sort($latencies);

        if ($started) {
            try {
                $this->store->cleanup($runId, $jobs);
            } catch (Throwable $exception) {
                $errorCode ??= class_basename($exception);
            }
        }

        return new QueueThroughputReport(
            environment: app()->environment(),
            runId: $runId,
            queue: $queue,
            expectedJobs: $jobs,
            completedJobs: $completedJobs,
            startedAt: $startedAt,
            finishedAt: $finishedAt,
            dispatchMilliseconds: $dispatchMilliseconds,
            measurementMilliseconds: $measurementMilliseconds,
            throughputPerSecond: $throughputPerSecond,
            latencyMilliseconds: [
                'minimum' => $latencies[0] ?? null,
                'p50' => $this->percentile($latencies, 50),
                'p95' => $this->percentile($latencies, 95),
                'p99' => $this->percentile($latencies, 99),
                'maximum' => $latencies === []
                    ? null
                    : $latencies[array_key_last($latencies)],
            ],
            budget: $budget,
            errorCode: $errorCode,
        );
    }

    /**
     * @param  list<int>  $sortedValues
     */
    private function percentile(array $sortedValues, int $percentile): ?int
    {
        if ($sortedValues === []) {
            return null;
        }

        $index = (int) ceil(
            ($percentile / 100) * count($sortedValues),
        ) - 1;

        return $sortedValues[max(0, $index)];
    }

    /**
     * @param  array{
     *     minimum_throughput_per_second: float,
     *     maximum_p95_latency_milliseconds: int,
     *     maximum_p99_latency_milliseconds: int
     * }  $budget
     */
    private function assertWorkload(
        string $queue,
        int $jobs,
        int $timeoutSeconds,
        array $budget,
    ): void {
        $this->configuration->assertQueue($queue);

        if (
            ! is_float($budget['minimum_throughput_per_second'] ?? null)
            || ! is_int(
                $budget['maximum_p95_latency_milliseconds'] ?? null,
            )
            || ! is_int(
                $budget['maximum_p99_latency_milliseconds'] ?? null,
            )
        ) {
            throw new \RuntimeException(
                'The queue throughput workload budget is invalid.',
            );
        }

        if (
            $this->configuration->jobs((string) $jobs) !== $jobs
            || $this->configuration->timeoutSeconds(
                (string) $timeoutSeconds,
            ) !== $timeoutSeconds
            || $this->configuration->budget(
                (string) $budget['minimum_throughput_per_second'],
                (string) $budget['maximum_p95_latency_milliseconds'],
                (string) $budget['maximum_p99_latency_milliseconds'],
            ) !== $budget
        ) {
            throw new \RuntimeException(
                'The queue throughput workload is invalid.',
            );
        }
    }
}
