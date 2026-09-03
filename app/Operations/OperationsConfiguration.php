<?php

namespace App\Operations;

use RuntimeException;

final class OperationsConfiguration
{
    public function dashboardMetricsCacheStore(): ?string
    {
        return $this->nullableString(
            config('operations.dashboard_metrics.cache_store'),
        ) ?? $this->cacheStore();
    }

    public function dashboardMetricsCacheKey(): string
    {
        $key = config('operations.dashboard_metrics.cache_key');

        if (
            ! is_string($key)
            || preg_match('/\A[a-zA-Z0-9:_-]{1,128}\z/', $key) !== 1
        ) {
            throw new RuntimeException(
                'The operational dashboard cache key is invalid.',
            );
        }

        return $key;
    }

    public function dashboardMetricsTtlSeconds(): int
    {
        return $this->boundedInteger(
            'operations.dashboard_metrics.ttl_seconds',
            5,
            300,
        );
    }

    public function dashboardMetricsLockSeconds(): int
    {
        return $this->boundedInteger(
            'operations.dashboard_metrics.lock_seconds',
            1,
            30,
        );
    }

    public function dashboardMetricsLockWaitSeconds(): int
    {
        return $this->boundedInteger(
            'operations.dashboard_metrics.lock_wait_seconds',
            1,
            10,
        );
    }

    public function databaseConnection(): ?string
    {
        return $this->nullableString(
            config('operations.readiness.database_connection'),
        );
    }

    public function cacheStore(): ?string
    {
        return $this->nullableString(
            config('operations.readiness.cache_store'),
        );
    }

    public function probeTtlSeconds(): int
    {
        return $this->boundedInteger(
            'operations.readiness.probe_ttl_seconds',
            5,
            60,
        );
    }

    public function queueHeartbeatsEnabled(): bool
    {
        return (bool) config(
            'operations.readiness.queue_heartbeats.enabled',
            false,
        );
    }

    /**
     * @return list<string>
     */
    public function queueNames(): array
    {
        $queues = config(
            'operations.readiness.queue_heartbeats.queues',
            [],
        );

        if (! is_array($queues) || $queues === [] || count($queues) > 20) {
            throw new RuntimeException(
                'Operational queue heartbeat names are not configured.',
            );
        }

        $validated = [];

        foreach ($queues as $queue) {
            if (
                ! is_string($queue)
                || preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $queue) !== 1
            ) {
                throw new RuntimeException(
                    'An operational queue heartbeat name is invalid.',
                );
            }

            $validated[] = $queue;
        }

        $unique = array_values(array_unique($validated));

        if (count($unique) !== count($validated)) {
            throw new RuntimeException(
                'Operational queue heartbeat names must be unique.',
            );
        }

        return $unique;
    }

    public function maximumHeartbeatAgeSeconds(): int
    {
        return $this->boundedInteger(
            'operations.readiness.queue_heartbeats.maximum_age_seconds',
            60,
            3600,
        );
    }

    public function maximumHeartbeatLatencySeconds(): int
    {
        return $this->boundedInteger(
            'operations.readiness.queue_heartbeats.maximum_latency_seconds',
            1,
            3600,
        );
    }

    public function heartbeatTtlSeconds(): int
    {
        $ttl = $this->boundedInteger(
            'operations.readiness.queue_heartbeats.ttl_seconds',
            120,
            86400,
        );

        if ($ttl <= $this->maximumHeartbeatAgeSeconds()) {
            throw new RuntimeException(
                'The queue heartbeat TTL must exceed its maximum age.',
            );
        }

        return $ttl;
    }

    public function heartbeatCacheKeyPrefix(): string
    {
        $prefix = config(
            'operations.readiness.queue_heartbeats.cache_key_prefix',
        );

        if (
            ! is_string($prefix)
            || preg_match('/\A[a-zA-Z0-9:_-]{1,128}\z/', $prefix) !== 1
        ) {
            throw new RuntimeException(
                'The operational queue heartbeat cache prefix is invalid.',
            );
        }

        return $prefix;
    }

    public function heartbeatLockSeconds(): int
    {
        return $this->boundedInteger(
            'operations.readiness.queue_heartbeats.lock_seconds',
            1,
            30,
        );
    }

    public function heartbeatLockWaitSeconds(): int
    {
        return $this->boundedInteger(
            'operations.readiness.queue_heartbeats.lock_wait_seconds',
            1,
            10,
        );
    }

    public function assertKnownQueue(string $queue): void
    {
        if (! in_array($queue, $this->queueNames(), true)) {
            throw new RuntimeException(
                'The operational queue heartbeat queue is not configured.',
            );
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new RuntimeException(
                'An operational readiness connection name is invalid.',
            );
        }

        return $value;
    }

    private function boundedInteger(
        string $key,
        int $minimum,
        int $maximum,
    ): int {
        $value = config($key);

        if (
            ! is_int($value)
            || $value < $minimum
            || $value > $maximum
        ) {
            throw new RuntimeException(
                "The {$key} operational setting is invalid.",
            );
        }

        return $value;
    }
}
