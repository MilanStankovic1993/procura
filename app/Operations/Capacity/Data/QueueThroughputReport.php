<?php

namespace App\Operations\Capacity\Data;

use Carbon\CarbonImmutable;

final readonly class QueueThroughputReport
{
    /**
     * @param  array{
     *     minimum_throughput_per_second: float,
     *     maximum_p95_latency_milliseconds: int,
     *     maximum_p99_latency_milliseconds: int
     * }  $budget
     * @param  array{
     *     minimum: int|null,
     *     p50: int|null,
     *     p95: int|null,
     *     p99: int|null,
     *     maximum: int|null
     * }  $latencyMilliseconds
     */
    public function __construct(
        public string $environment,
        public string $runId,
        public string $queue,
        public int $expectedJobs,
        public int $completedJobs,
        public CarbonImmutable $startedAt,
        public CarbonImmutable $finishedAt,
        public float $dispatchMilliseconds,
        public float $measurementMilliseconds,
        public float $throughputPerSecond,
        public array $latencyMilliseconds,
        public array $budget,
        public ?string $errorCode = null,
    ) {}

    public function passed(): bool
    {
        return $this->errorCode === null
            && $this->completedJobs === $this->expectedJobs
            && $this->throughputPerSecond
                >= $this->budget['minimum_throughput_per_second']
            && $this->latencyMilliseconds['p95'] !== null
            && $this->latencyMilliseconds['p95']
                <= $this->budget['maximum_p95_latency_milliseconds']
            && $this->latencyMilliseconds['p99'] !== null
            && $this->latencyMilliseconds['p99']
                <= $this->budget['maximum_p99_latency_milliseconds'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->errorCode !== null
                ? 'error'
                : ($this->passed() ? 'passed' : 'failed'),
            'environment' => $this->environment,
            'run_id' => $this->runId,
            'queue' => $this->queue,
            'started_at' => $this->startedAt->toIso8601String(),
            'finished_at' => $this->finishedAt->toIso8601String(),
            'expected_jobs' => $this->expectedJobs,
            'completed_jobs' => $this->completedJobs,
            'missed_jobs' => $this->expectedJobs - $this->completedJobs,
            'dispatch_milliseconds' => round(
                $this->dispatchMilliseconds,
                3,
            ),
            'measurement_milliseconds' => round(
                $this->measurementMilliseconds,
                3,
            ),
            'throughput_per_second' => round(
                $this->throughputPerSecond,
                3,
            ),
            'latency_milliseconds' => $this->latencyMilliseconds,
            'budget' => $this->budget,
            'error_code' => $this->errorCode,
        ];
    }
}
