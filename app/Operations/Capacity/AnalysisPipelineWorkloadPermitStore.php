<?php

namespace App\Operations\Capacity;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Cache\CacheManager;
use RuntimeException;
use Throwable;

final class AnalysisPipelineWorkloadPermitStore
{
    public function __construct(
        private readonly AnalysisPipelineWorkloadConfiguration $configuration,
        private readonly CacheManager $cache,
    ) {}

    /**
     * @param  list<User>  $actors
     * @return array{token: string, expires_at: string, mutation_requests: int}
     */
    public function issue(
        array $actors,
        int $scenarios,
        int $ttlSeconds,
    ): array {
        $token = rtrim(strtr(
            base64_encode(random_bytes(32)),
            '+/',
            '-_',
        ), '=');
        $expiresAt = CarbonImmutable::now('UTC')->addSeconds($ttlSeconds);
        $actorIds = array_values(array_map(
            static fn (User $actor): string => (string) $actor->getKey(),
            $actors,
        ));
        sort($actorIds);
        $mutationRequests = $scenarios * 2;

        $stored = $this->cache->store($this->configuration->cacheStore())->put(
            $this->key($token),
            [
                'version' => 1,
                'actor_ids' => $actorIds,
                'remaining_mutation_requests' => $mutationRequests,
                'expires_at' => $expiresAt->getTimestamp(),
            ],
            $expiresAt,
        );

        if ($stored !== true) {
            throw new RuntimeException(
                'The analysis workload permit could not be stored.',
            );
        }

        return [
            'token' => $token,
            'expires_at' => $expiresAt->toIso8601String(),
            'mutation_requests' => $mutationRequests,
        ];
    }

    public function consume(User $actor, string $token): bool
    {
        if (preg_match('/\A[A-Za-z0-9_-]{43}\z/', $token) !== 1) {
            return false;
        }

        $repository = $this->cache->store(
            $this->configuration->cacheStore(),
        );
        $key = $this->key($token);

        try {
            return $repository->lock(
                "{$key}:lock",
                $this->configuration->lockSeconds(),
            )->block(
                $this->configuration->lockWaitSeconds(),
                function () use ($repository, $key, $actor): bool {
                    $permit = $repository->get($key);

                    if (! $this->validPermit($permit, $actor)) {
                        return false;
                    }

                    $permit['remaining_mutation_requests']--;
                    $expiresAt = CarbonImmutable::createFromTimestampUTC(
                        $permit['expires_at'],
                    );

                    return $repository->put($key, $permit, $expiresAt) === true;
                },
            );
        } catch (Throwable) {
            return false;
        }
    }

    public function revoke(string $token): void
    {
        $this->cache->store($this->configuration->cacheStore())
            ->forget($this->key($token));
    }

    private function key(string $token): string
    {
        return $this->configuration->cacheKeyPrefix()
            .':'.hash('sha256', $token);
    }

    private function validPermit(mixed $permit, User $actor): bool
    {
        return is_array($permit)
            && ($permit['version'] ?? null) === 1
            && is_array($permit['actor_ids'] ?? null)
            && in_array((string) $actor->getKey(), $permit['actor_ids'], true)
            && is_int($permit['remaining_mutation_requests'] ?? null)
            && $permit['remaining_mutation_requests'] > 0
            && is_int($permit['expires_at'] ?? null)
            && $permit['expires_at'] > CarbonImmutable::now('UTC')->getTimestamp();
    }
}
