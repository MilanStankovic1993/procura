<?php

namespace App\Operations;

use App\Operations\Data\OperationalReadinessReport;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Throwable;

final class OperationalReadiness
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly CacheFactory $cache,
        private readonly OperationsConfiguration $configuration,
        private readonly QueueHeartbeatStore $heartbeats,
    ) {}

    public function inspect(
        bool $requireQueueHeartbeats = false,
    ): OperationalReadinessReport {
        $checkedAt = CarbonImmutable::now('UTC');
        $databaseStatus = $this->databaseStatus();
        $cacheStatus = $this->cacheStatus();
        $queueStatus = 'not_monitored';
        $queueChecks = [];
        $queueReady = true;

        try {
            $heartbeatsEnabled = (
                $this->configuration->queueHeartbeatsEnabled()
            );

            if ($requireQueueHeartbeats && ! $heartbeatsEnabled) {
                $queueStatus = 'not_configured';
                $queueReady = false;
            } elseif ($heartbeatsEnabled) {
                [$queueReady, $queueChecks] = $this->queueStatuses(
                    $checkedAt,
                );
                $queueStatus = $queueReady ? 'ok' : 'unavailable';
            }
        } catch (Throwable) {
            $queueStatus = 'unavailable';
            $queueReady = false;
        }

        $checks = [
            'database' => $databaseStatus,
            'cache' => $cacheStatus,
            'queues' => $queueStatus,
        ];

        return new OperationalReadinessReport(
            ready: (
                $databaseStatus === 'ok'
                && $cacheStatus === 'ok'
                && $queueReady
            ),
            checkedAt: $checkedAt,
            checks: $checks,
            queueChecks: $queueChecks,
        );
    }

    private function databaseStatus(): string
    {
        try {
            $this->database
                ->connection($this->configuration->databaseConnection())
                ->selectOne('select 1');

            return 'ok';
        } catch (Throwable) {
            return 'unavailable';
        }
    }

    private function cacheStatus(): string
    {
        try {
            $key = sprintf(
                '%s:readiness-probe:%s',
                $this->configuration->heartbeatCacheKeyPrefix(),
                Str::uuid()->toString(),
            );
            $value = Str::random(40);
            $repository = $this->cache->store(
                $this->configuration->cacheStore(),
            );
            $repository->put(
                $key,
                $value,
                $this->configuration->probeTtlSeconds(),
            );
            $matches = hash_equals(
                $value,
                (string) $repository->get($key),
            );
            $repository->forget($key);

            return $matches ? 'ok' : 'unavailable';
        } catch (Throwable) {
            return 'unavailable';
        }
    }

    /**
     * @return array{
     *     bool,
     *     array<string, array{
     *         status: string,
     *         age_seconds: int|null,
     *         latency_milliseconds: int|null
     *     }>
     * }
     */
    private function queueStatuses(CarbonImmutable $checkedAt): array
    {
        $checks = [];
        $ready = true;
        $maximumAge = (
            $this->configuration->maximumHeartbeatAgeSeconds()
        );
        $maximumLatencyMilliseconds = (
            $this->configuration->maximumHeartbeatLatencySeconds() * 1000
        );

        foreach ($this->configuration->queueNames() as $queue) {
            try {
                $heartbeat = $this->heartbeats->find($queue);

                if ($heartbeat === null) {
                    $checks[$queue] = [
                        'status' => 'missing',
                        'age_seconds' => null,
                        'latency_milliseconds' => null,
                    ];
                    $ready = false;

                    continue;
                }

                $age = $heartbeat->processedAgeSeconds($checkedAt);
                $isFresh = (
                    $heartbeat->processedAt <= $checkedAt
                    && $age <= $maximumAge
                    && $heartbeat->latencyMilliseconds
                        <= $maximumLatencyMilliseconds
                );

                $checks[$queue] = [
                    'status' => $isFresh ? 'ok' : 'stale',
                    'age_seconds' => $age,
                    'latency_milliseconds' => (
                        $heartbeat->latencyMilliseconds
                    ),
                ];
                $ready = $ready && $isFresh;
            } catch (Throwable) {
                $checks[$queue] = [
                    'status' => 'unavailable',
                    'age_seconds' => null,
                    'latency_milliseconds' => null,
                ];
                $ready = false;
            }
        }

        return [$ready, $checks];
    }
}
