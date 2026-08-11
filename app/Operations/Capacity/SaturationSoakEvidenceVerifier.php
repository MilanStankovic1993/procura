<?php

namespace App\Operations\Capacity;

use App\Operations\Capacity\Data\SaturationSoakEvidenceReport;

final class SaturationSoakEvidenceVerifier
{
    public function __construct(
        private readonly SaturationSoakEvidenceParser $parser,
        private readonly SaturationSoakEvidenceConfiguration $configuration,
    ) {}

    public function verify(
        mixed $payload,
        string $expectedRelease,
    ): SaturationSoakEvidenceReport {
        $evidence = $this->parser->parse($payload, $expectedRelease);
        $budgets = $this->configuration->budgets();
        $samples = $evidence['samples'];
        $activeSamples = array_values(array_filter(
            $samples,
            static fn (array $sample): bool => in_array(
                $sample['phase'],
                ['saturation', 'soak'],
                true,
            ),
        ));
        $soakSamples = array_values(array_filter(
            $samples,
            static fn (array $sample): bool => $sample['phase'] === 'soak',
        ));
        $first = $samples[0];
        $last = $samples[array_key_last($samples)];
        $activeFirst = $activeSamples[0];
        $activeLast = $activeSamples[array_key_last($activeSamples)];

        $utilization = [
            'database_connections' => $this->statistics(
                $this->values(
                    $activeSamples,
                    'database',
                    'connection_utilization_basis_points',
                ),
            ),
            'database_cpu' => $this->statistics(
                $this->values(
                    $activeSamples,
                    'database',
                    'cpu_utilization_basis_points',
                ),
            ),
            'redis_memory' => $this->statistics(
                $this->values(
                    $activeSamples,
                    'redis',
                    'memory_utilization_basis_points',
                ),
            ),
            'redis_cpu' => $this->statistics(
                $this->values(
                    $activeSamples,
                    'redis',
                    'cpu_utilization_basis_points',
                ),
            ),
            'host_cpu' => $this->statistics(
                $this->values(
                    $activeSamples,
                    'host',
                    'cpu_utilization_basis_points',
                ),
            ),
            'host_memory' => $this->statistics(
                $this->values(
                    $activeSamples,
                    'host',
                    'memory_utilization_basis_points',
                ),
            ),
        ];
        $databasePressure = $this->statistics(array_map(
            static fn (array $sample): int => max(
                $sample['database'][
                    'connection_utilization_basis_points'
                ],
                $sample['database']['cpu_utilization_basis_points'],
            ),
            $soakSamples,
        ));
        $redisPressure = $this->statistics(array_map(
            static fn (array $sample): int => max(
                $sample['redis']['memory_utilization_basis_points'],
                $sample['redis']['cpu_utilization_basis_points'],
            ),
            $soakSamples,
        ));
        $queues = [];
        $workers = [];
        $workerRestartDeltas = [];

        foreach ($this->configuration->queues() as $queue) {
            $queues[$queue] = [
                'depth' => $this->statistics(array_map(
                    static fn (array $sample): int => (
                        $sample['queues'][$queue]['depth']
                    ),
                    $activeSamples,
                )),
                'oldest_job_age_milliseconds' => $this->statistics(
                    array_map(
                        static fn (array $sample): int => (
                            $sample['queues'][$queue][
                                'oldest_job_age_milliseconds'
                            ]
                        ),
                        $activeSamples,
                    ),
                ),
            ];
            $workers[$queue] = [
                'active_busy_utilization_basis_points' => $this->statistics(
                    array_map(
                        static fn (array $sample): int => (
                            $sample['workers'][$queue][
                                'busy_utilization_basis_points'
                            ]
                        ),
                        $activeSamples,
                    ),
                ),
                'soak_busy_utilization_basis_points' => $this->statistics(
                    array_map(
                        static fn (array $sample): int => (
                            $sample['workers'][$queue][
                                'busy_utilization_basis_points'
                            ]
                        ),
                        $soakSamples,
                    ),
                ),
            ];
            $workerRestartDeltas[$queue] = (
                $last['workers'][$queue]['restarts_total']
                - $first['workers'][$queue]['restarts_total']
            );
        }

        $counterDeltas = [
            'http_requests' => $this->delta(
                $first,
                $last,
                'application',
                'http_requests_total',
            ),
            'http_server_errors' => $this->delta(
                $first,
                $last,
                'application',
                'http_server_errors_total',
            ),
            'jobs_processed' => $this->delta(
                $first,
                $last,
                'application',
                'jobs_processed_total',
            ),
            'jobs_failed' => $this->delta(
                $first,
                $last,
                'application',
                'jobs_failed_total',
            ),
            'database_deadlocks' => $this->delta(
                $first,
                $last,
                'database',
                'deadlocks_total',
            ),
            'redis_evicted_keys' => $this->delta(
                $first,
                $last,
                'redis',
                'evicted_keys_total',
            ),
            'redis_rejected_connections' => $this->delta(
                $first,
                $last,
                'redis',
                'rejected_connections_total',
            ),
            'worker_restarts' => $workerRestartDeltas,
        ];
        $loadCounterDeltas = [
            'http_requests' => $this->delta(
                $activeFirst,
                $activeLast,
                'application',
                'http_requests_total',
            ),
            'http_server_errors' => $this->delta(
                $activeFirst,
                $activeLast,
                'application',
                'http_server_errors_total',
            ),
            'jobs_processed' => $this->delta(
                $activeFirst,
                $activeLast,
                'application',
                'jobs_processed_total',
            ),
            'jobs_failed' => $this->delta(
                $activeFirst,
                $activeLast,
                'application',
                'jobs_failed_total',
            ),
        ];
        $rates = [
            'http_server_error_rate_basis_points' => $this->rate(
                $counterDeltas['http_server_errors'],
                $counterDeltas['http_requests'],
            ),
            'job_failure_rate_basis_points' => $this->rate(
                $counterDeltas['jobs_failed'],
                $counterDeltas['jobs_processed'],
            ),
        ];
        $recovery = [
            'utilization_basis_points' => [
                'database_connections' => $last['database'][
                    'connection_utilization_basis_points'
                ],
                'database_cpu' => $last['database'][
                    'cpu_utilization_basis_points'
                ],
                'redis_memory' => $last['redis'][
                    'memory_utilization_basis_points'
                ],
                'redis_cpu' => $last['redis'][
                    'cpu_utilization_basis_points'
                ],
                'host_cpu' => $last['host'][
                    'cpu_utilization_basis_points'
                ],
                'host_memory' => $last['host'][
                    'memory_utilization_basis_points'
                ],
            ],
            'queues' => [],
            'workers' => [],
        ];

        foreach ($this->configuration->queues() as $queue) {
            $recovery['queues'][$queue] = $last['queues'][$queue];
            $recovery['workers'][$queue] = [
                'busy_utilization_basis_points' => $last['workers'][$queue][
                    'busy_utilization_basis_points'
                ],
            ];
        }

        $metrics = [
            'active_utilization_basis_points' => $utilization,
            'soak_pressure_basis_points' => [
                'database' => $databasePressure,
                'redis' => $redisPressure,
            ],
            'queues' => $queues,
            'workers' => $workers,
            'counter_deltas' => $counterDeltas,
            'load_counter_deltas' => $loadCounterDeltas,
            'rates_basis_points' => $rates,
            'recovery' => $recovery,
        ];

        return new SaturationSoakEvidenceReport(
            reportContractVersion: $this->configuration
                ->reportContractVersion(),
            environment: app()->environment(),
            releaseSha: $evidence['release_sha'],
            sampleIntervalSeconds: $evidence['sample_interval_seconds'],
            sampleCount: count($samples),
            startedAt: $evidence['started_at'],
            finishedAt: $evidence['finished_at'],
            phaseDurationsSeconds: $evidence[
                'phase_durations_seconds'
            ],
            metrics: $metrics,
            budget: $budgets,
            failedChecks: $this->failedChecks(
                $metrics,
                $budgets,
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @param  array<string, mixed>  $budgets
     * @return list<string>
     */
    private function failedChecks(array $metrics, array $budgets): array
    {
        $failed = [];
        $counters = $metrics['counter_deltas'];
        $loadCounters = $metrics['load_counter_deltas'];
        $rates = $metrics['rates_basis_points'];
        $utilization = $metrics['active_utilization_basis_points'];
        $pressure = $metrics['soak_pressure_basis_points'];
        $recovery = $metrics['recovery'];

        $this->requireAtLeast(
            $failed,
            'http_request_volume',
            $budgets['minimum_http_requests'],
            $loadCounters['http_requests'],
        );
        $this->requireAtLeast(
            $failed,
            'job_volume',
            $budgets['minimum_jobs_processed'],
            $loadCounters['jobs_processed'],
        );
        $this->requireAtMost(
            $failed,
            'http_server_error_rate',
            $budgets['maximum_http_server_error_rate_basis_points'],
            $rates['http_server_error_rate_basis_points'],
        );
        $this->requireAtMost(
            $failed,
            'job_failure_rate',
            $budgets['maximum_job_failure_rate_basis_points'],
            $rates['job_failure_rate_basis_points'],
        );
        $this->requireAtMost(
            $failed,
            'database_deadlocks',
            $budgets['maximum_database_deadlocks'],
            $counters['database_deadlocks'],
        );
        $this->requireAtMost(
            $failed,
            'redis_evicted_keys',
            $budgets['maximum_redis_evicted_keys'],
            $counters['redis_evicted_keys'],
        );
        $this->requireAtMost(
            $failed,
            'redis_rejected_connections',
            $budgets['maximum_redis_rejected_connections'],
            $counters['redis_rejected_connections'],
        );

        foreach ([
            'database_connections' => (
                'maximum_database_connection_p95_basis_points'
            ),
            'database_cpu' => 'maximum_database_cpu_p95_basis_points',
            'redis_memory' => 'maximum_redis_memory_p95_basis_points',
            'redis_cpu' => 'maximum_redis_cpu_p95_basis_points',
            'host_cpu' => 'maximum_host_cpu_p95_basis_points',
            'host_memory' => 'maximum_host_memory_p95_basis_points',
        ] as $metric => $budget) {
            $this->requireAtMost(
                $failed,
                "{$metric}_p95",
                $budgets[$budget],
                $utilization[$metric]['p95'],
            );
        }

        foreach (['database', 'redis'] as $resource) {
            $this->requireAtLeast(
                $failed,
                "{$resource}_soak_pressure",
                $budgets['minimum_soak_pressure_p50_basis_points'],
                $pressure[$resource]['p50'],
            );
        }

        foreach ($this->configuration->queues() as $queue) {
            $this->requireAtMost(
                $failed,
                "queue_{$queue}_depth",
                $budgets['maximum_queue_depth'][$queue],
                $metrics['queues'][$queue]['depth']['maximum'],
            );
            $this->requireAtMost(
                $failed,
                "queue_{$queue}_oldest_job_age",
                $budgets[
                    'maximum_queue_oldest_job_age_milliseconds'
                ][$queue],
                $metrics['queues'][$queue][
                    'oldest_job_age_milliseconds'
                ]['maximum'],
            );
            $workerP95 = $metrics['workers'][$queue][
                'active_busy_utilization_basis_points'
            ]['p95'];
            $workerSoakP50 = $metrics['workers'][$queue][
                'soak_busy_utilization_basis_points'
            ]['p50'];
            $this->requireAtLeast(
                $failed,
                "worker_{$queue}_soak_pressure",
                $budgets['minimum_soak_pressure_p50_basis_points'],
                $workerSoakP50,
            );
            $this->requireAtMost(
                $failed,
                "worker_{$queue}_busy_p95",
                $budgets['maximum_worker_busy_p95_basis_points'],
                $workerP95,
            );
            $this->requireAtMost(
                $failed,
                "worker_{$queue}_restarts",
                $budgets['maximum_worker_restarts'],
                $counters['worker_restarts'][$queue],
            );
            $this->requireAtMost(
                $failed,
                "recovery_queue_{$queue}_depth",
                $budgets['maximum_recovery_queue_depth'],
                $recovery['queues'][$queue]['depth'],
            );
            $this->requireAtMost(
                $failed,
                "recovery_queue_{$queue}_oldest_job_age",
                $budgets[
                    'maximum_recovery_oldest_job_age_milliseconds'
                ],
                $recovery['queues'][$queue][
                    'oldest_job_age_milliseconds'
                ],
            );
            $this->requireAtMost(
                $failed,
                "recovery_worker_{$queue}_busy",
                $budgets['maximum_recovery_utilization_basis_points'],
                $recovery['workers'][$queue][
                    'busy_utilization_basis_points'
                ],
            );
        }

        foreach (
            $recovery['utilization_basis_points'] as $metric => $value
        ) {
            $this->requireAtMost(
                $failed,
                "recovery_{$metric}",
                $budgets['maximum_recovery_utilization_basis_points'],
                $value,
            );
        }

        return $failed;
    }

    /**
     * A minimum requirement fails when the observed value is below it.
     *
     * @param  list<string>  $failed
     */
    private function requireAtLeast(
        array &$failed,
        string $check,
        int $requiredMinimum,
        int $observed,
    ): void {
        if ($observed < $requiredMinimum) {
            $failed[] = $check;
        }
    }

    /**
     * A maximum budget fails when the observed value exceeds it.
     *
     * @param  list<string>  $failed
     */
    private function requireAtMost(
        array &$failed,
        string $check,
        int $allowedMaximum,
        int $observed,
    ): void {
        if ($observed > $allowedMaximum) {
            $failed[] = $check;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $samples
     * @return list<int>
     */
    private function values(
        array $samples,
        string $group,
        string $metric,
    ): array {
        return array_map(
            static fn (array $sample): int => $sample[$group][$metric],
            $samples,
        );
    }

    /**
     * @param  list<int>  $values
     * @return array{minimum: int, p50: int, p95: int, p99: int, maximum: int}
     */
    private function statistics(array $values): array
    {
        sort($values);

        return [
            'minimum' => $values[0],
            'p50' => $this->percentile($values, 50),
            'p95' => $this->percentile($values, 95),
            'p99' => $this->percentile($values, 99),
            'maximum' => $values[array_key_last($values)],
        ];
    }

    /**
     * @param  list<int>  $sortedValues
     */
    private function percentile(array $sortedValues, int $percentile): int
    {
        $index = (int) ceil(
            ($percentile / 100) * count($sortedValues),
        ) - 1;

        return $sortedValues[max(0, $index)];
    }

    /**
     * @param  array<string, mixed>  $first
     * @param  array<string, mixed>  $last
     */
    private function delta(
        array $first,
        array $last,
        string $group,
        string $metric,
    ): int {
        return $last[$group][$metric] - $first[$group][$metric];
    }

    private function rate(int $events, int $total): int
    {
        if ($events === 0) {
            return 0;
        }

        if ($total === 0) {
            return 10_000;
        }

        return min(
            10_000,
            (int) ceil(($events / $total) * 10_000),
        );
    }
}
