<?php

namespace App\Operations\Capacity;

use RuntimeException;

final class BrowserWorkloadConfiguration
{
    /** @var list<string> */
    private const SCENARIOS = [
        'overview',
        'buy_index',
        'sell_index',
    ];

    public function enabled(): bool
    {
        return config('performance.browser_workload.enabled') === true;
    }

    public function cacheStore(): string
    {
        $store = config('performance.browser_workload.cache_store')
            ?: config('operations.readiness.cache_store')
            ?: config('cache.default');

        if (
            ! is_string($store)
            || preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $store) !== 1
        ) {
            throw new RuntimeException('The browser workload cache store is invalid.');
        }

        return $store;
    }

    public function samplesPerScenario(mixed $value): int
    {
        $maximum = $this->configuredInteger('maximum_samples_per_scenario', 1, 100);
        $default = $this->configuredInteger('default_samples_per_scenario', 1, 100);

        if ($default > $maximum) {
            throw new RuntimeException('The default browser sample count exceeds its maximum.');
        }

        if ($value === null || $value === '') {
            return $default;
        }

        if (! is_string($value) || preg_match('/\A[0-9]+\z/', $value) !== 1) {
            throw new RuntimeException('The browser sample count option is invalid.');
        }

        $samples = (int) $value;

        if ($samples < 1 || $samples > $maximum) {
            throw new RuntimeException('The browser sample count option is out of range.');
        }

        return $samples;
    }

    public function minimumEvidenceSamples(): int
    {
        $minimum = $this->configuredInteger('minimum_evidence_samples_per_scenario', 1, 100);
        $maximum = $this->configuredInteger('maximum_samples_per_scenario', 1, 100);

        if ($minimum > $maximum) {
            throw new RuntimeException('The browser evidence minimum exceeds its maximum.');
        }

        return $minimum;
    }

    public function permitTtlSeconds(mixed $value): int
    {
        $maximum = $this->configuredInteger('maximum_permit_ttl_seconds', 60, 3600);
        $default = $this->configuredInteger('default_permit_ttl_seconds', 60, 3600);

        if ($default > $maximum) {
            throw new RuntimeException('The default browser workload permit TTL exceeds its maximum.');
        }

        if ($value === null || $value === '') {
            return $default;
        }

        if (! is_string($value) || preg_match('/\A[0-9]+\z/', $value) !== 1) {
            throw new RuntimeException('The browser workload permit TTL is invalid.');
        }

        $ttl = (int) $value;

        if ($ttl < 60 || $ttl > $maximum) {
            throw new RuntimeException('The browser workload permit TTL is out of range.');
        }

        return $ttl;
    }

    public function header(): string
    {
        $header = config('performance.browser_workload.header');

        if (! is_string($header) || preg_match('/\A[A-Za-z0-9-]{1,64}\z/', $header) !== 1) {
            throw new RuntimeException('The browser workload permit header is invalid.');
        }

        return $header;
    }

    public function cacheKeyPrefix(): string
    {
        $prefix = config('performance.browser_workload.cache_key_prefix');

        if (! is_string($prefix) || preg_match('/\A[a-zA-Z0-9:_-]{1,128}\z/', $prefix) !== 1) {
            throw new RuntimeException('The browser workload cache key prefix is invalid.');
        }

        return $prefix;
    }

    public function lockSeconds(): int
    {
        return $this->configuredInteger('lock_seconds', 1, 30);
    }

    public function lockWaitSeconds(): int
    {
        return $this->configuredInteger('lock_wait_seconds', 1, 10);
    }

    /** @return list<string> */
    public function scenarios(): array
    {
        $scenarios = config('performance.browser_workload.scenarios');

        if (! is_array($scenarios) || array_values($scenarios) !== self::SCENARIOS) {
            throw new RuntimeException('The browser workload scenario contract is invalid.');
        }

        return self::SCENARIOS;
    }

    public function origin(): string
    {
        $origin = config('app.frontend_url');

        if (! is_string($origin) || trim($origin) !== $origin) {
            throw new RuntimeException('The browser workload origin is invalid.');
        }

        $parts = parse_url($origin);

        if (
            ! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || ($parts['host'] ?? '') === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)
        ) {
            throw new RuntimeException('The browser workload origin is invalid.');
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return 'https://'.strtolower($parts['host']).$port;
    }

    /**
     * @return array{
     *     version: string,
     *     maximum_document_ttfb_p95_milliseconds: int,
     *     maximum_route_ready_p95_milliseconds: int,
     *     maximum_lcp_p95_milliseconds: int,
     *     maximum_cls_p95: float,
     *     maximum_failure_rate_basis_points: int
     * }
     */
    public function budgets(): array
    {
        $version = config('performance.browser_workload.budgets.version');
        $maximumCls = config('performance.browser_workload.budgets.maximum_cls_p95');

        if (
            ! is_string($version)
            || preg_match('/\A[a-z0-9:_-]{1,128}\z/', $version) !== 1
            || ! is_float($maximumCls)
            || ! is_finite($maximumCls)
            || $maximumCls <= 0
            || $maximumCls > 1
        ) {
            throw new RuntimeException('The browser workload budget configuration is invalid.');
        }

        return [
            'version' => $version,
            'maximum_document_ttfb_p95_milliseconds' => $this->budgetInteger(
                'maximum_document_ttfb_p95_milliseconds',
                1,
                60_000,
            ),
            'maximum_route_ready_p95_milliseconds' => $this->budgetInteger(
                'maximum_route_ready_p95_milliseconds',
                1,
                120_000,
            ),
            'maximum_lcp_p95_milliseconds' => $this->budgetInteger(
                'maximum_lcp_p95_milliseconds',
                1,
                60_000,
            ),
            'maximum_cls_p95' => $maximumCls,
            'maximum_failure_rate_basis_points' => $this->budgetInteger(
                'maximum_failure_rate_basis_points',
                0,
                10_000,
            ),
        ];
    }

    /**
     * @return array{
     *     version: string,
     *     viewport_width: int,
     *     viewport_height: int,
     *     cpu_slowdown_rate: int,
     *     network_latency_milliseconds: int,
     *     download_bits_per_second: int,
     *     upload_bits_per_second: int
     * }
     */
    public function profile(): array
    {
        $version = config('performance.browser_workload.profile.version');

        if (! is_string($version) || preg_match('/\A[a-z0-9:_-]{1,128}\z/', $version) !== 1) {
            throw new RuntimeException('The browser workload profile version is invalid.');
        }

        return [
            'version' => $version,
            'viewport_width' => $this->profileInteger('viewport_width', 320, 3840),
            'viewport_height' => $this->profileInteger('viewport_height', 480, 2160),
            'cpu_slowdown_rate' => $this->profileInteger('cpu_slowdown_rate', 1, 20),
            'network_latency_milliseconds' => $this->profileInteger(
                'network_latency_milliseconds',
                0,
                2000,
            ),
            'download_bits_per_second' => $this->profileInteger(
                'download_bits_per_second',
                100_000,
                1_000_000_000,
            ),
            'upload_bits_per_second' => $this->profileInteger(
                'upload_bits_per_second',
                100_000,
                1_000_000_000,
            ),
        ];
    }

    public function assertStagingInfrastructure(): void
    {
        $cacheStore = $this->cacheStore();

        if (
            ! $this->enabled()
            || config("cache.stores.{$cacheStore}.driver") !== 'redis'
            || config('session.driver') !== 'redis'
        ) {
            throw new RuntimeException('The staging browser workload infrastructure is invalid.');
        }

        $this->origin();
        $this->scenarios();
        $this->minimumEvidenceSamples();
        $this->budgets();
        $this->profile();
    }

    private function configuredInteger(string $key, int $minimum, int $maximum): int
    {
        $value = config("performance.browser_workload.{$key}");

        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new RuntimeException("The browser workload {$key} setting is invalid.");
        }

        return $value;
    }

    private function budgetInteger(string $key, int $minimum, int $maximum): int
    {
        $value = config("performance.browser_workload.budgets.{$key}");

        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new RuntimeException("The browser workload {$key} budget is invalid.");
        }

        return $value;
    }

    private function profileInteger(string $key, int $minimum, int $maximum): int
    {
        $value = config("performance.browser_workload.profile.{$key}");

        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new RuntimeException("The browser workload {$key} profile setting is invalid.");
        }

        return $value;
    }
}
