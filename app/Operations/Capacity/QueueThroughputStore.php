<?php

namespace App\Operations\Capacity;

use App\Operations\Capacity\Data\QueueThroughputReceipt;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use RuntimeException;

final class QueueThroughputStore
{
    public function __construct(
        private readonly CacheFactory $cache,
        private readonly QueueThroughputConfiguration $configuration,
    ) {}

    public function start(string $runId, int $expectedJobs): void
    {
        $this->assertRunIdentity($runId, $expectedJobs);

        if (
            ! $this->repository()->add(
                $this->runKey($runId),
                [
                    'version' => 1,
                    'run_id' => $runId,
                    'expected_jobs' => $expectedJobs,
                ],
                $this->configuration->receiptTtlSeconds(),
            )
        ) {
            throw new RuntimeException(
                'The queue throughput run identifier already exists.',
            );
        }
    }

    public function record(
        string $runId,
        int $sequence,
        CarbonImmutable $dispatchedAt,
        ?CarbonImmutable $processedAt = null,
    ): bool {
        $this->assertRunIdentity($runId, $sequence);
        $run = $this->repository()->get($this->runKey($runId));

        if (
            ! is_array($run)
            || ($run['version'] ?? null) !== 1
            || ($run['run_id'] ?? null) !== $runId
            || ! is_int($run['expected_jobs'] ?? null)
            || $sequence < 1
            || $sequence > $run['expected_jobs']
        ) {
            return false;
        }

        $processedAt ??= CarbonImmutable::now('UTC');
        $dispatchedAtMilliseconds = $dispatchedAt->getTimestampMs();
        $processedAtMilliseconds = $processedAt->getTimestampMs();
        $latencyMilliseconds = $processedAtMilliseconds
            - $dispatchedAtMilliseconds;

        if ($latencyMilliseconds < 0) {
            throw new RuntimeException(
                'A queue throughput probe cannot finish before dispatch.',
            );
        }

        $receipt = new QueueThroughputReceipt(
            runId: $runId,
            sequence: $sequence,
            dispatchedAtMilliseconds: $dispatchedAtMilliseconds,
            processedAtMilliseconds: $processedAtMilliseconds,
            latencyMilliseconds: $latencyMilliseconds,
        );

        return $this->repository()->add(
            $this->receiptKey($runId, $sequence),
            $receipt->toCachePayload(),
            $this->configuration->receiptTtlSeconds(),
        );
    }

    /**
     * @return array<int, QueueThroughputReceipt>
     */
    public function collect(string $runId, int $expectedJobs): array
    {
        $this->assertRunIdentity($runId, $expectedJobs);
        $keys = $this->receiptKeys($runId, $expectedJobs);
        $payloads = $this->repository()->many($keys);
        $receipts = [];

        foreach ($keys as $key) {
            $payload = $payloads[$key] ?? null;

            if ($payload === null) {
                continue;
            }

            if (! is_array($payload)) {
                throw new RuntimeException(
                    'A queue throughput receipt payload is invalid.',
                );
            }

            $receipt = QueueThroughputReceipt::fromCachePayload($payload);

            if (
                $receipt->runId !== $runId
                || $receipt->sequence > $expectedJobs
            ) {
                throw new RuntimeException(
                    'A queue throughput receipt identity is invalid.',
                );
            }

            $receipts[$receipt->sequence] = $receipt;
        }

        ksort($receipts);

        return $receipts;
    }

    public function cleanup(string $runId, int $expectedJobs): void
    {
        $this->assertRunIdentity($runId, $expectedJobs);
        $repository = $this->repository();
        $repository->forget($this->runKey($runId));
        $repository->deleteMultiple(
            $this->receiptKeys($runId, $expectedJobs),
        );
    }

    public function repository(): Repository
    {
        return $this->cache->store(
            $this->configuration->cacheStore(),
        );
    }

    /**
     * @return list<string>
     */
    private function receiptKeys(string $runId, int $expectedJobs): array
    {
        $keys = [];

        for ($sequence = 1; $sequence <= $expectedJobs; $sequence++) {
            $keys[] = $this->receiptKey($runId, $sequence);
        }

        return $keys;
    }

    private function runKey(string $runId): string
    {
        return sprintf(
            '%s:%s:run',
            $this->configuration->cacheKeyPrefix(),
            $runId,
        );
    }

    private function receiptKey(string $runId, int $sequence): string
    {
        return sprintf(
            '%s:%s:receipt:%d',
            $this->configuration->cacheKeyPrefix(),
            $runId,
            $sequence,
        );
    }

    private function assertRunIdentity(
        string $runId,
        int $upperBound,
    ): void {
        if (
            preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $runId) !== 1
            || $upperBound < 1
            || $this->configuration->jobs((string) $upperBound)
                !== $upperBound
        ) {
            throw new RuntimeException(
                'The queue throughput run identity is invalid.',
            );
        }
    }
}
