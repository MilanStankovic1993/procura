<?php

namespace App\Operations\Capacity;

use App\Analysis\Contracts\ListingAiAnalyzer;
use App\Analysis\Providers\FakeListingAiAnalyzer;
use App\ProductMatching\Contracts\ProductMatcher;
use App\ProductMatching\Providers\FakeCatalogProductMatcher;
use RuntimeException;
use Throwable;

final class AnalysisPipelineWorkloadConfiguration
{
    public function enabled(): bool
    {
        return config('performance.analysis_pipeline_workload.enabled') === true;
    }

    public function cacheStore(): string
    {
        $store = config('performance.analysis_pipeline_workload.cache_store')
            ?: config('operations.readiness.cache_store')
            ?: config('cache.default');

        if (
            ! is_string($store)
            || preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $store) !== 1
        ) {
            throw new RuntimeException(
                'The analysis workload cache store is invalid.',
            );
        }

        return $store;
    }

    public function scenarios(mixed $value): int
    {
        $maximum = $this->configuredInteger('maximum_scenarios', 1, 250);
        $default = $this->configuredInteger('default_scenarios', 1, 250);

        if ($default > $maximum) {
            throw new RuntimeException(
                'The default analysis workload exceeds its maximum.',
            );
        }

        return $this->optionInteger(
            $value,
            $default,
            1,
            $maximum,
            'scenario count',
        );
    }

    public function permitTtlSeconds(mixed $value): int
    {
        $maximum = $this->configuredInteger(
            'maximum_permit_ttl_seconds',
            60,
            3600,
        );
        $default = $this->configuredInteger(
            'default_permit_ttl_seconds',
            60,
            3600,
        );

        if ($default > $maximum) {
            throw new RuntimeException(
                'The default analysis workload permit TTL exceeds its maximum.',
            );
        }

        return $this->optionInteger(
            $value,
            $default,
            60,
            $maximum,
            'permit TTL',
        );
    }

    public function header(): string
    {
        $header = config('performance.analysis_pipeline_workload.header');

        if (
            ! is_string($header)
            || preg_match('/\A[A-Za-z0-9-]{1,64}\z/', $header) !== 1
        ) {
            throw new RuntimeException(
                'The analysis workload permit header is invalid.',
            );
        }

        return $header;
    }

    public function cacheKeyPrefix(): string
    {
        $prefix = config(
            'performance.analysis_pipeline_workload.cache_key_prefix',
        );

        if (
            ! is_string($prefix)
            || preg_match('/\A[a-zA-Z0-9:_-]{1,128}\z/', $prefix) !== 1
        ) {
            throw new RuntimeException(
                'The analysis workload cache key prefix is invalid.',
            );
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

    /**
     * @return array{
     *     version: string,
     *     minimum_throughput_per_second: float,
     *     maximum_draft_p95_milliseconds: int,
     *     maximum_submit_p95_milliseconds: int,
     *     maximum_pipeline_p95_milliseconds: int,
     *     maximum_failure_rate_basis_points: int
     * }
     */
    public function budgets(): array
    {
        $version = config(
            'performance.analysis_pipeline_workload.budgets.version',
        );
        $minimumThroughput = config(
            'performance.analysis_pipeline_workload.budgets.minimum_throughput_per_second',
        );

        if (
            ! is_string($version)
            || preg_match('/\A[a-z0-9:_-]{1,128}\z/', $version) !== 1
            || ! is_float($minimumThroughput)
            || ! is_finite($minimumThroughput)
            || $minimumThroughput <= 0
        ) {
            throw new RuntimeException(
                'The analysis workload budget configuration is invalid.',
            );
        }

        return [
            'version' => $version,
            'minimum_throughput_per_second' => $minimumThroughput,
            'maximum_draft_p95_milliseconds' => $this->configuredBudgetInteger(
                'maximum_draft_p95_milliseconds',
                1,
                300_000,
            ),
            'maximum_submit_p95_milliseconds' => $this->configuredBudgetInteger(
                'maximum_submit_p95_milliseconds',
                1,
                300_000,
            ),
            'maximum_pipeline_p95_milliseconds' => $this->configuredBudgetInteger(
                'maximum_pipeline_p95_milliseconds',
                1,
                3_600_000,
            ),
            'maximum_failure_rate_basis_points' => $this->configuredBudgetInteger(
                'maximum_failure_rate_basis_points',
                0,
                10_000,
            ),
        ];
    }

    public function assertStagingInfrastructure(): void
    {
        $cacheStore = $this->cacheStore();

        if (
            ! $this->enabled()
            || config('analyses.submission_enabled') !== true
            || config('queue.default') !== 'redis'
            || config("cache.stores.{$cacheStore}.driver") !== 'redis'
        ) {
            throw new RuntimeException(
                'The staging analysis workload infrastructure is invalid.',
            );
        }
    }

    public function productionShapedProviders(): bool
    {
        try {
            return ! app(ListingAiAnalyzer::class) instanceof FakeListingAiAnalyzer
                && ! app(ProductMatcher::class) instanceof FakeCatalogProductMatcher;
        } catch (Throwable) {
            return false;
        }
    }

    private function configuredInteger(
        string $key,
        int $minimum,
        int $maximum,
    ): int {
        $value = config("performance.analysis_pipeline_workload.{$key}");

        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new RuntimeException(
                "The analysis workload {$key} setting is invalid.",
            );
        }

        return $value;
    }

    private function configuredBudgetInteger(
        string $key,
        int $minimum,
        int $maximum,
    ): int {
        $value = config(
            "performance.analysis_pipeline_workload.budgets.{$key}",
        );

        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new RuntimeException(
                "The analysis workload {$key} budget is invalid.",
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
                "The analysis workload {$label} option is invalid.",
            );
        }

        $integer = (int) $value;

        if ($integer < $minimum || $integer > $maximum) {
            throw new RuntimeException(
                "The analysis workload {$label} option is out of range.",
            );
        }

        return $integer;
    }
}
