<?php

namespace App\Operations\Data;

use Carbon\CarbonImmutable;
use RuntimeException;

final readonly class QueueHeartbeat
{
    public function __construct(
        public string $queue,
        public CarbonImmutable $dispatchedAt,
        public CarbonImmutable $processedAt,
        public int $latencyMilliseconds,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromCachePayload(array $payload): self
    {
        if (
            ($payload['version'] ?? null) !== 1
            || ! is_string($payload['queue'] ?? null)
            || ! is_int($payload['dispatched_at_ms'] ?? null)
            || ! is_int($payload['processed_at_ms'] ?? null)
            || ! is_int($payload['latency_ms'] ?? null)
            || $payload['latency_ms'] < 0
        ) {
            throw new RuntimeException(
                'The operational queue heartbeat payload is invalid.',
            );
        }

        return new self(
            queue: $payload['queue'],
            dispatchedAt: self::fromEpochMilliseconds(
                $payload['dispatched_at_ms'],
            ),
            processedAt: self::fromEpochMilliseconds(
                $payload['processed_at_ms'],
            ),
            latencyMilliseconds: $payload['latency_ms'],
        );
    }

    public function processedAgeSeconds(CarbonImmutable $now): int
    {
        return max(
            0,
            (int) floor(
                ($now->getTimestampMs() - $this->processedAt->getTimestampMs())
                / 1000,
            ),
        );
    }

    private static function fromEpochMilliseconds(
        int $milliseconds,
    ): CarbonImmutable {
        $seconds = intdiv($milliseconds, 1000);
        $remainingMilliseconds = $milliseconds % 1000;

        return CarbonImmutable::createFromTimestampUTC($seconds)
            ->addMilliseconds($remainingMilliseconds);
    }
}
