<?php

namespace App\Operations\Capacity\Data;

use RuntimeException;

final readonly class QueueThroughputReceipt
{
    public function __construct(
        public string $runId,
        public int $sequence,
        public int $dispatchedAtMilliseconds,
        public int $processedAtMilliseconds,
        public int $latencyMilliseconds,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromCachePayload(array $payload): self
    {
        foreach ([
            'run_id',
            'sequence',
            'dispatched_at_ms',
            'processed_at_ms',
            'latency_ms',
        ] as $key) {
            if (! array_key_exists($key, $payload)) {
                throw new RuntimeException(
                    'A queue throughput receipt is incomplete.',
                );
            }
        }

        if (
            ($payload['version'] ?? null) !== 1
            || ! is_string($payload['run_id'])
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $payload['run_id']) !== 1
            || ! is_int($payload['sequence'])
            || $payload['sequence'] < 1
            || ! is_int($payload['dispatched_at_ms'])
            || ! is_int($payload['processed_at_ms'])
            || ! is_int($payload['latency_ms'])
            || $payload['latency_ms'] < 0
            || $payload['latency_ms']
                !== $payload['processed_at_ms'] - $payload['dispatched_at_ms']
        ) {
            throw new RuntimeException(
                'A queue throughput receipt is invalid.',
            );
        }

        return new self(
            runId: $payload['run_id'],
            sequence: $payload['sequence'],
            dispatchedAtMilliseconds: $payload['dispatched_at_ms'],
            processedAtMilliseconds: $payload['processed_at_ms'],
            latencyMilliseconds: $payload['latency_ms'],
        );
    }

    /**
     * @return array<string, int|string>
     */
    public function toCachePayload(): array
    {
        return [
            'version' => 1,
            'run_id' => $this->runId,
            'sequence' => $this->sequence,
            'dispatched_at_ms' => $this->dispatchedAtMilliseconds,
            'processed_at_ms' => $this->processedAtMilliseconds,
            'latency_ms' => $this->latencyMilliseconds,
        ];
    }
}
