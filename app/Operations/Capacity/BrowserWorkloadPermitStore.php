<?php

namespace App\Operations\Capacity;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Cache\CacheManager;
use RuntimeException;
use Throwable;

final class BrowserWorkloadPermitStore
{
    public function __construct(
        private readonly BrowserWorkloadConfiguration $configuration,
        private readonly CacheManager $cache,
    ) {}

    /**
     * @param  list<User>  $actors
     * @param  list<string>  $scenarios
     * @return array{token: string, expires_at: string, authorizations: int}
     */
    public function issue(
        array $actors,
        array $scenarios,
        int $samplesPerScenario,
        int $ttlSeconds,
        string $contractHash,
    ): array {
        if (preg_match('/\A[a-f0-9]{64}\z/', $contractHash) !== 1) {
            throw new RuntimeException('The browser workload contract hash is invalid.');
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expiresAt = CarbonImmutable::now('UTC')->addSeconds($ttlSeconds);
        $actorIds = array_values(array_map(
            static fn (User $actor): string => (string) $actor->getKey(),
            $actors,
        ));
        sort($actorIds);
        $remaining = array_fill_keys($scenarios, $samplesPerScenario);

        $stored = $this->cache->store($this->configuration->cacheStore())->put(
            $this->key($token),
            [
                'version' => 1,
                'actor_ids' => $actorIds,
                'remaining_by_scenario' => $remaining,
                'contract_hash' => $contractHash,
                'expires_at' => $expiresAt->getTimestamp(),
            ],
            $expiresAt,
        );

        if ($stored !== true) {
            throw new RuntimeException('The browser workload permit could not be stored.');
        }

        return [
            'token' => $token,
            'expires_at' => $expiresAt->toIso8601String(),
            'authorizations' => count($scenarios) * $samplesPerScenario,
        ];
    }

    public function consume(User $actor, string $token, string $scenario, string $contractHash): bool
    {
        if (
            preg_match('/\A[A-Za-z0-9_-]{43}\z/', $token) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $contractHash) !== 1
        ) {
            return false;
        }

        $repository = $this->cache->store($this->configuration->cacheStore());
        $key = $this->key($token);

        try {
            return $repository->lock(
                "{$key}:lock",
                $this->configuration->lockSeconds(),
            )->block(
                $this->configuration->lockWaitSeconds(),
                function () use ($repository, $key, $actor, $scenario, $contractHash): bool {
                    $permit = $repository->get($key);

                    if (! $this->validPermit($permit, $actor, $scenario, $contractHash)) {
                        return false;
                    }

                    $permit['remaining_by_scenario'][$scenario]--;
                    $expiresAt = CarbonImmutable::createFromTimestampUTC($permit['expires_at']);

                    return $repository->put($key, $permit, $expiresAt) === true;
                },
            );
        } catch (Throwable) {
            return false;
        }
    }

    public function revoke(string $token): void
    {
        $this->cache->store($this->configuration->cacheStore())->forget($this->key($token));
    }

    private function key(string $token): string
    {
        return $this->configuration->cacheKeyPrefix().':'.hash('sha256', $token);
    }

    private function validPermit(
        mixed $permit,
        User $actor,
        string $scenario,
        string $contractHash,
    ): bool {
        return is_array($permit)
            && ($permit['version'] ?? null) === 1
            && is_array($permit['actor_ids'] ?? null)
            && in_array((string) $actor->getKey(), $permit['actor_ids'], true)
            && is_array($permit['remaining_by_scenario'] ?? null)
            && is_int($permit['remaining_by_scenario'][$scenario] ?? null)
            && $permit['remaining_by_scenario'][$scenario] > 0
            && hash_equals($permit['contract_hash'] ?? '', $contractHash)
            && is_int($permit['expires_at'] ?? null)
            && $permit['expires_at'] > CarbonImmutable::now('UTC')->getTimestamp();
    }
}
