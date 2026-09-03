<?php

namespace App\Operations;

use App\Operations\Data\QueueHeartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use RuntimeException;

final class QueueHeartbeatStore
{
    public function __construct(
        private readonly CacheFactory $cache,
        private readonly OperationsConfiguration $configuration,
    ) {}

    public function record(
        string $queue,
        CarbonImmutable $dispatchedAt,
        ?CarbonImmutable $processedAt = null,
    ): bool {
        $this->configuration->assertKnownQueue($queue);

        $processedAt ??= CarbonImmutable::now('UTC');
        $dispatchedAtMilliseconds = $dispatchedAt->getTimestampMs();
        $processedAtMilliseconds = $processedAt->getTimestampMs();
        $latencyMilliseconds = (
            $processedAtMilliseconds - $dispatchedAtMilliseconds
        );

        if ($latencyMilliseconds < 0) {
            throw new RuntimeException(
                'A queue heartbeat cannot be processed before dispatch.',
            );
        }

        $repository = $this->repository();
        $key = $this->key($queue);
        $lock = $repository->lock(
            "{$key}:lock",
            $this->configuration->heartbeatLockSeconds(),
        );

        return (bool) $lock->block(
            $this->configuration->heartbeatLockWaitSeconds(),
            function () use (
                $repository,
                $key,
                $queue,
                $dispatchedAtMilliseconds,
                $processedAtMilliseconds,
                $latencyMilliseconds,
            ): bool {
                $current = $repository->get($key);

                if (
                    is_array($current)
                    && is_int($current['dispatched_at_ms'] ?? null)
                    && $current['dispatched_at_ms']
                        >= $dispatchedAtMilliseconds
                ) {
                    return false;
                }

                $repository->put(
                    $key,
                    [
                        'version' => 1,
                        'queue' => $queue,
                        'dispatched_at_ms' => $dispatchedAtMilliseconds,
                        'processed_at_ms' => $processedAtMilliseconds,
                        'latency_ms' => $latencyMilliseconds,
                    ],
                    $this->configuration->heartbeatTtlSeconds(),
                );

                return true;
            },
        );
    }

    public function find(string $queue): ?QueueHeartbeat
    {
        $this->configuration->assertKnownQueue($queue);

        $payload = $this->repository()->get($this->key($queue));

        if ($payload === null) {
            return null;
        }

        if (! is_array($payload)) {
            throw new RuntimeException(
                'The operational queue heartbeat payload is invalid.',
            );
        }

        $heartbeat = QueueHeartbeat::fromCachePayload($payload);

        if ($heartbeat->queue !== $queue) {
            throw new RuntimeException(
                'The operational queue heartbeat identity is invalid.',
            );
        }

        return $heartbeat;
    }

    public function repository(): Repository
    {
        return $this->cache->store(
            $this->configuration->cacheStore(),
        );
    }

    private function key(string $queue): string
    {
        return sprintf(
            '%s:%s',
            $this->configuration->heartbeatCacheKeyPrefix(),
            $queue,
        );
    }
}
