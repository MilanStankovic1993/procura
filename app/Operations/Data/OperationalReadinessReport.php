<?php

namespace App\Operations\Data;

use Carbon\CarbonImmutable;

final readonly class OperationalReadinessReport
{
    /**
     * @param  array{database: string, cache: string, queues: string}  $checks
     * @param  array<string, array{
     *     status: string,
     *     age_seconds: int|null,
     *     latency_milliseconds: int|null
     * }>  $queueChecks
     */
    public function __construct(
        public bool $ready,
        public CarbonImmutable $checkedAt,
        public array $checks,
        public array $queueChecks,
    ) {}

    public function status(): string
    {
        return $this->ready ? 'ok' : 'unavailable';
    }

    /**
     * @return array{
     *     service: string,
     *     status: string,
     *     version: string,
     *     timestamp: string,
     *     checks: array{database: string, cache: string, queues: string}
     * }
     */
    public function publicPayload(): array
    {
        return [
            'service' => 'procura-api',
            'status' => $this->status(),
            'version' => 'v1',
            'timestamp' => $this->checkedAt->toIso8601String(),
            'checks' => $this->checks,
        ];
    }

    /**
     * @return array{
     *     status: string,
     *     timestamp: string,
     *     checks: array{database: string, cache: string, queues: string},
     *     queue_heartbeats: array<string, array{
     *         status: string,
     *         age_seconds: int|null,
     *         latency_milliseconds: int|null
     *     }>
     * }
     */
    public function operatorPayload(): array
    {
        return [
            'status' => $this->status(),
            'timestamp' => $this->checkedAt->toIso8601String(),
            'checks' => $this->checks,
            'queue_heartbeats' => $this->queueChecks,
        ];
    }
}
