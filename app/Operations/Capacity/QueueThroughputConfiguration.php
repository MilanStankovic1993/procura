<?php

namespace App\Operations\Capacity;

use App\Operations\OperationsConfiguration;
use RuntimeException;

final class QueueThroughputConfiguration
{
    public function __construct(
        private readonly OperationsConfiguration $operations,
    ) {}

    public function cacheStore(): string
    {
        $store = $this->operations->cacheStore()
            ?? config('cache.default');

        if (
            ! is_string($store)
            || preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $store) !== 1
        ) {
            throw new RuntimeException(
                'The queue throughput cache store is invalid.',
            );
        }

        return $store;
    }

    public function assertQueue(string $queue): void
    {
        $this->operations->assertKnownQueue($queue);
    }

    public function jobs(mixed $value): int
    {
        $maximum = $this->configuredInteger('maximum_jobs', 1, 5000);
        $default = $this->configuredInteger('default_jobs', 1, 5000);

        if ($default > $maximum) {
            throw new RuntimeException(
                'The default queue throughput job count exceeds its maximum.',
            );
        }

        return $this->optionInteger(
            value: $value,
            default: $default,
            minimum: 1,
            maximum: $maximum,
            label: 'job count',
        );
    }

    public function timeoutSeconds(mixed $value): int
    {
        $maximum = $this->configuredInteger(
            'maximum_timeout_seconds',
            1,
            300,
        );
        $default = $this->configuredInteger(
            'default_timeout_seconds',
            1,
            300,
        );

        if ($default > $maximum) {
            throw new RuntimeException(
                'The default queue throughput timeout exceeds its maximum.',
            );
        }

        return $this->optionInteger(
            value: $value,
            default: $default,
            minimum: 1,
            maximum: $maximum,
            label: 'timeout',
        );
    }

    public function receiptTtlSeconds(): int
    {
        $ttl = $this->configuredInteger(
            'receipt_ttl_seconds',
            60,
            3600,
        );

        if ($ttl <= $this->configuredInteger(
            'maximum_timeout_seconds',
            1,
            300,
        )) {
            throw new RuntimeException(
                'The queue throughput receipt TTL must exceed the maximum timeout.',
            );
        }

        return $ttl;
    }

    public function pollIntervalMilliseconds(): int
    {
        return $this->configuredInteger(
            'poll_interval_milliseconds',
            10,
            1000,
        );
    }

    public function cacheKeyPrefix(): string
    {
        $prefix = config(
            'performance.queue_throughput.cache_key_prefix',
        );

        if (
            ! is_string($prefix)
            || preg_match('/\A[a-zA-Z0-9:_-]{1,128}\z/', $prefix) !== 1
        ) {
            throw new RuntimeException(
                'The queue throughput cache key prefix is invalid.',
            );
        }

        return $prefix;
    }

    /**
     * @return array{
     *     minimum_throughput_per_second: float,
     *     maximum_p95_latency_milliseconds: int,
     *     maximum_p99_latency_milliseconds: int
     * }
     */
    public function budget(
        mixed $minimumThroughput,
        mixed $maximumP95Milliseconds,
        mixed $maximumP99Milliseconds,
    ): array {
        $configuredMinimum = $this->configuredFloat(
            'budgets.minimum_throughput_per_second',
            0.001,
            1_000_000_000_000,
        );
        $configuredP95 = $this->configuredInteger(
            'budgets.maximum_p95_latency_milliseconds',
            1,
            300_000,
        );
        $configuredP99 = $this->configuredInteger(
            'budgets.maximum_p99_latency_milliseconds',
            1,
            300_000,
        );

        $minimum = $this->optionFloat(
            $minimumThroughput,
            $configuredMinimum,
            0.001,
            1_000_000_000_000,
            'minimum throughput',
        );
        $p95 = $this->optionInteger(
            $maximumP95Milliseconds,
            $configuredP95,
            1,
            300_000,
            'maximum p95 latency',
        );
        $p99 = $this->optionInteger(
            $maximumP99Milliseconds,
            $configuredP99,
            1,
            300_000,
            'maximum p99 latency',
        );

        if (
            $configuredP99 < $configuredP95
            || $minimum < $configuredMinimum
            || $p95 > $configuredP95
            || $p99 > $configuredP99
            || $p99 < $p95
        ) {
            throw new RuntimeException(
                'Queue throughput overrides may only tighten valid versioned budgets.',
            );
        }

        return [
            'minimum_throughput_per_second' => $minimum,
            'maximum_p95_latency_milliseconds' => $p95,
            'maximum_p99_latency_milliseconds' => $p99,
        ];
    }

    public function assertStagingInfrastructure(): void
    {
        $cacheStore = $this->cacheStore();

        if (
            config('queue.default') !== 'redis'
            || config("cache.stores.{$cacheStore}.driver") !== 'redis'
        ) {
            throw new RuntimeException(
                'Staging throughput evidence requires Redis queue and cache drivers.',
            );
        }
    }

    private function configuredInteger(
        string $key,
        int $minimum,
        int $maximum,
    ): int {
        $value = config("performance.queue_throughput.{$key}");

        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new RuntimeException(
                "The queue throughput {$key} setting is invalid.",
            );
        }

        return $value;
    }

    private function configuredFloat(
        string $key,
        float $minimum,
        float $maximum,
    ): float {
        $value = config("performance.queue_throughput.{$key}");

        if (
            ! is_float($value)
            || ! is_finite($value)
            || $value < $minimum
            || $value > $maximum
        ) {
            throw new RuntimeException(
                "The queue throughput {$key} setting is invalid.",
            );
        }

        return $value;
    }

    private function optionInteger(
        mixed $value,
        int $default,
        int $minimum,
        int $maximum,
        string $label,
    ): int {
        if ($value === null || $value === '') {
            return $default;
        }

        if (
            ! is_string($value)
            || preg_match('/\A[0-9]+\z/', $value) !== 1
        ) {
            throw new RuntimeException(
                "The queue throughput {$label} option is invalid.",
            );
        }

        $integer = (int) $value;

        if ($integer < $minimum || $integer > $maximum) {
            throw new RuntimeException(
                "The queue throughput {$label} option is out of range.",
            );
        }

        return $integer;
    }

    private function optionFloat(
        mixed $value,
        float $default,
        float $minimum,
        float $maximum,
        string $label,
    ): float {
        if ($value === null || $value === '') {
            return $default;
        }

        if (! is_string($value) || ! is_numeric($value)) {
            throw new RuntimeException(
                "The queue throughput {$label} option is invalid.",
            );
        }

        $float = (float) $value;

        if (
            ! is_finite($float)
            || $float < $minimum
            || $float > $maximum
        ) {
            throw new RuntimeException(
                "The queue throughput {$label} option is out of range.",
            );
        }

        return $float;
    }
}
