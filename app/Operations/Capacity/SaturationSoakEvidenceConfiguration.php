<?php

namespace App\Operations\Capacity;

use App\Operations\OperationsConfiguration;
use RuntimeException;

final class SaturationSoakEvidenceConfiguration
{
    public function __construct(
        private readonly OperationsConfiguration $operations,
    ) {}

    public function inputContractVersion(): string
    {
        return $this->version('input_contract_version');
    }

    public function reportContractVersion(): string
    {
        return $this->version('report_contract_version');
    }

    public function budgetVersion(): string
    {
        return $this->version('budget_version');
    }

    /**
     * @return list<string>
     */
    public function queues(): array
    {
        return $this->operations->queueNames();
    }

    public function maximumFileBytes(): int
    {
        return $this->integer('maximum_file_bytes', 1024, 52_428_800);
    }

    public function privateDirectory(): string
    {
        $directory = config(
            'performance.saturation_soak_evidence.private_directory',
        );

        if (
            ! is_string($directory)
            || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}\z/', $directory)
                !== 1
        ) {
            throw new RuntimeException(
                'The saturation evidence private directory is invalid.',
            );
        }

        return $directory;
    }

    public function maximumSamples(): int
    {
        return $this->integer('maximum_samples', 8, 100_000);
    }

    public function minimumSampleIntervalSeconds(): int
    {
        return $this->integer(
            'sample_interval_seconds.minimum',
            1,
            300,
        );
    }

    public function maximumSampleIntervalSeconds(): int
    {
        $maximum = $this->integer(
            'sample_interval_seconds.maximum',
            1,
            300,
        );

        if ($maximum < $this->minimumSampleIntervalSeconds()) {
            throw new RuntimeException(
                'The saturation evidence sample interval range is invalid.',
            );
        }

        return $maximum;
    }

    public function maximumGapMultiplier(): int
    {
        return $this->integer(
            'sample_interval_seconds.maximum_gap_multiplier',
            1,
            10,
        );
    }

    /**
     * @return array<string, int>
     */
    public function phaseMinimumDurations(): array
    {
        $durations = [];

        foreach ($this->phases() as $phase) {
            $durations[$phase] = $this->integer(
                "phases.{$phase}.minimum_duration_seconds",
                1,
                604_800,
            );
        }

        return $durations;
    }

    /**
     * @return list<string>
     */
    public function phases(): array
    {
        return ['baseline', 'saturation', 'soak', 'recovery'];
    }

    /**
     * @return array<string, mixed>
     */
    public function budgets(): array
    {
        $maximumQueueDepth = $this->queueBudget(
            'maximum_queue_depth',
            0,
            10_000_000,
        );
        $maximumOldestAge = $this->queueBudget(
            'maximum_queue_oldest_job_age_milliseconds',
            0,
            86_400_000,
        );

        $budget = [
            'version' => $this->budgetVersion(),
            'minimum_http_requests' => $this->budgetInteger(
                'minimum_http_requests',
                1,
                PHP_INT_MAX,
            ),
            'minimum_jobs_processed' => $this->budgetInteger(
                'minimum_jobs_processed',
                1,
                PHP_INT_MAX,
            ),
            'minimum_soak_pressure_p50_basis_points' => (
                $this->budgetInteger(
                    'minimum_soak_pressure_p50_basis_points',
                    1,
                    10_000,
                )
            ),
            'maximum_database_connection_p95_basis_points' => (
                $this->budgetInteger(
                    'maximum_database_connection_p95_basis_points',
                    1,
                    10_000,
                )
            ),
            'maximum_database_cpu_p95_basis_points' => (
                $this->budgetInteger(
                    'maximum_database_cpu_p95_basis_points',
                    1,
                    10_000,
                )
            ),
            'maximum_redis_memory_p95_basis_points' => (
                $this->budgetInteger(
                    'maximum_redis_memory_p95_basis_points',
                    1,
                    10_000,
                )
            ),
            'maximum_redis_cpu_p95_basis_points' => (
                $this->budgetInteger(
                    'maximum_redis_cpu_p95_basis_points',
                    1,
                    10_000,
                )
            ),
            'maximum_host_cpu_p95_basis_points' => (
                $this->budgetInteger(
                    'maximum_host_cpu_p95_basis_points',
                    1,
                    10_000,
                )
            ),
            'maximum_host_memory_p95_basis_points' => (
                $this->budgetInteger(
                    'maximum_host_memory_p95_basis_points',
                    1,
                    10_000,
                )
            ),
            'maximum_worker_busy_p95_basis_points' => (
                $this->budgetInteger(
                    'maximum_worker_busy_p95_basis_points',
                    1,
                    10_000,
                )
            ),
            'maximum_recovery_utilization_basis_points' => (
                $this->budgetInteger(
                    'maximum_recovery_utilization_basis_points',
                    1,
                    10_000,
                )
            ),
            'maximum_http_server_error_rate_basis_points' => (
                $this->budgetInteger(
                    'maximum_http_server_error_rate_basis_points',
                    0,
                    10_000,
                )
            ),
            'maximum_job_failure_rate_basis_points' => (
                $this->budgetInteger(
                    'maximum_job_failure_rate_basis_points',
                    0,
                    10_000,
                )
            ),
            'maximum_database_deadlocks' => $this->budgetInteger(
                'maximum_database_deadlocks',
                0,
                1_000_000,
            ),
            'maximum_redis_evicted_keys' => $this->budgetInteger(
                'maximum_redis_evicted_keys',
                0,
                1_000_000,
            ),
            'maximum_redis_rejected_connections' => (
                $this->budgetInteger(
                    'maximum_redis_rejected_connections',
                    0,
                    1_000_000,
                )
            ),
            'maximum_worker_restarts' => $this->budgetInteger(
                'maximum_worker_restarts',
                0,
                1_000_000,
            ),
            'maximum_queue_depth' => $maximumQueueDepth,
            'maximum_queue_oldest_job_age_milliseconds' => (
                $maximumOldestAge
            ),
            'maximum_recovery_queue_depth' => $this->budgetInteger(
                'maximum_recovery_queue_depth',
                0,
                10_000_000,
            ),
            'maximum_recovery_oldest_job_age_milliseconds' => (
                $this->budgetInteger(
                    'maximum_recovery_oldest_job_age_milliseconds',
                    0,
                    86_400_000,
                )
            ),
        ];

        $minimumPressure = $budget['minimum_soak_pressure_p50_basis_points'];

        foreach ([
            'maximum_database_connection_p95_basis_points',
            'maximum_database_cpu_p95_basis_points',
            'maximum_redis_memory_p95_basis_points',
            'maximum_redis_cpu_p95_basis_points',
            'maximum_worker_busy_p95_basis_points',
        ] as $key) {
            if ($budget[$key] < $minimumPressure) {
                throw new RuntimeException(
                    'The saturation evidence pressure budget is inconsistent.',
                );
            }
        }

        $recoveryMaximum = $budget[
            'maximum_recovery_utilization_basis_points'
        ];

        foreach ([
            'maximum_database_connection_p95_basis_points',
            'maximum_database_cpu_p95_basis_points',
            'maximum_redis_memory_p95_basis_points',
            'maximum_redis_cpu_p95_basis_points',
            'maximum_host_cpu_p95_basis_points',
            'maximum_host_memory_p95_basis_points',
            'maximum_worker_busy_p95_basis_points',
        ] as $key) {
            if ($recoveryMaximum > $budget[$key]) {
                throw new RuntimeException(
                    'The saturation evidence recovery budget is inconsistent.',
                );
            }
        }

        foreach ($this->queues() as $queue) {
            if (
                $budget['maximum_recovery_queue_depth']
                    > $budget['maximum_queue_depth'][$queue]
                || $budget[
                    'maximum_recovery_oldest_job_age_milliseconds'
                ] > $budget[
                    'maximum_queue_oldest_job_age_milliseconds'
                ][$queue]
            ) {
                throw new RuntimeException(
                    'The saturation evidence queue recovery budget is inconsistent.',
                );
            }
        }

        return $budget;
    }

    public function releaseSha(mixed $value): string
    {
        if (
            ! is_string($value)
            || preg_match('/\A(?:[0-9a-f]{40}|[0-9a-f]{64})\z/', $value)
                !== 1
        ) {
            throw new RuntimeException(
                'The saturation evidence release commit is invalid.',
            );
        }

        return $value;
    }

    public function inputPath(mixed $value): string
    {
        if (
            ! is_string($value)
            || preg_match(
                '/\A[a-zA-Z0-9][a-zA-Z0-9._\/-]{0,254}\.json\z/',
                $value,
            ) !== 1
            || str_contains($value, '..')
            || str_contains($value, '//')
        ) {
            throw new RuntimeException(
                'The saturation evidence input path is invalid.',
            );
        }

        $root = storage_path('app/private/'.$this->privateDirectory());
        $path = $root.'/'.str_replace('/', DIRECTORY_SEPARATOR, $value);
        $realRoot = realpath($root);
        $realPath = realpath($path);

        if (
            $realRoot === false
            || $realPath === false
            || ! is_file($realPath)
            || ! str_starts_with(
                $realPath,
                $realRoot.DIRECTORY_SEPARATOR,
            )
        ) {
            throw new RuntimeException(
                'The saturation evidence input file is unavailable.',
            );
        }

        $size = filesize($realPath);

        if (
            $size === false
            || $size < 2
            || $size > $this->maximumFileBytes()
        ) {
            throw new RuntimeException(
                'The saturation evidence input file size is invalid.',
            );
        }

        return $realPath;
    }

    private function version(string $key): string
    {
        $version = config(
            "performance.saturation_soak_evidence.{$key}",
        );

        if (
            ! is_string($version)
            || preg_match('/\A[a-z0-9][a-z0-9:_-]{1,127}\z/', $version)
                !== 1
        ) {
            throw new RuntimeException(
                "The saturation evidence {$key} is invalid.",
            );
        }

        return $version;
    }

    private function integer(
        string $key,
        int $minimum,
        int $maximum,
    ): int {
        $value = config(
            "performance.saturation_soak_evidence.{$key}",
        );

        if (
            ! is_int($value)
            || $value < $minimum
            || $value > $maximum
        ) {
            throw new RuntimeException(
                "The saturation evidence {$key} is invalid.",
            );
        }

        return $value;
    }

    private function budgetInteger(
        string $key,
        int $minimum,
        int $maximum,
    ): int {
        return $this->integer("budgets.{$key}", $minimum, $maximum);
    }

    /**
     * @return array<string, int>
     */
    private function queueBudget(
        string $key,
        int $minimum,
        int $maximum,
    ): array {
        $values = config(
            "performance.saturation_soak_evidence.budgets.{$key}",
        );
        $queues = $this->queues();

        if (! is_array($values) || ! $this->sameKeys($values, $queues)) {
            throw new RuntimeException(
                "The saturation evidence {$key} queue budget is invalid.",
            );
        }

        $validated = [];

        foreach ($queues as $queue) {
            $value = $values[$queue];

            if (! is_int($value) || $value < $minimum || $value > $maximum) {
                throw new RuntimeException(
                    "The saturation evidence {$key} queue budget is invalid.",
                );
            }

            $validated[$queue] = $value;
        }

        return $validated;
    }

    /**
     * @param  array<mixed>  $values
     * @param  list<string>  $expected
     */
    private function sameKeys(array $values, array $expected): bool
    {
        $actual = array_keys($values);
        sort($actual);
        sort($expected);

        return $actual === $expected;
    }
}
