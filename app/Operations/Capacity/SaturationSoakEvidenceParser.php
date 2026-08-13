<?php

namespace App\Operations\Capacity;

use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

final class SaturationSoakEvidenceParser
{
    public function __construct(
        private readonly SaturationSoakEvidenceConfiguration $configuration,
    ) {}

    /**
     * @return array{
     *     release_sha: string,
     *     sample_interval_seconds: int,
     *     started_at: CarbonImmutable,
     *     finished_at: CarbonImmutable,
     *     phase_durations_seconds: array<string, int>,
     *     samples: list<array<string, mixed>>
     * }
     */
    public function parse(mixed $payload, string $expectedRelease): array
    {
        if (! is_array($payload) || array_is_list($payload)) {
            throw new RuntimeException(
                'The saturation evidence root must be an object.',
            );
        }

        $this->assertExactKeys($payload, [
            'contract_version',
            'release_sha',
            'environment',
            'sample_interval_seconds',
            'samples',
        ]);

        if (
            $payload['contract_version']
                !== $this->configuration->inputContractVersion()
            || $payload['environment'] !== 'staging'
        ) {
            throw new RuntimeException(
                'The saturation evidence contract or environment is invalid.',
            );
        }

        $releaseSha = $this->configuration->releaseSha(
            $payload['release_sha'],
        );

        if ($releaseSha !== $expectedRelease) {
            throw new RuntimeException(
                'The saturation evidence release commit does not match.',
            );
        }

        $sampleInterval = $this->boundedInteger(
            $payload['sample_interval_seconds'],
            $this->configuration->minimumSampleIntervalSeconds(),
            $this->configuration->maximumSampleIntervalSeconds(),
        );
        $rawSamples = $payload['samples'];

        if (
            ! is_array($rawSamples)
            || ! array_is_list($rawSamples)
            || count($rawSamples) < 8
            || count($rawSamples) > $this->configuration->maximumSamples()
        ) {
            throw new RuntimeException(
                'The saturation evidence sample count is invalid.',
            );
        }

        $samples = [];
        $previous = null;
        $previousPhaseIndex = 0;
        $phases = $this->configuration->phases();
        $phaseBounds = [];
        $maximumGap = $sampleInterval
            * $this->configuration->maximumGapMultiplier();

        foreach ($rawSamples as $index => $rawSample) {
            $sample = $this->sample($rawSample);
            $phaseIndex = array_search(
                $sample['phase'],
                $phases,
                true,
            );

            if (
                ! is_int($phaseIndex)
                || ($index === 0 && $phaseIndex !== 0)
                || $phaseIndex < $previousPhaseIndex
                || $phaseIndex > $previousPhaseIndex + 1
            ) {
                throw new RuntimeException(
                    'The saturation evidence phase order is invalid.',
                );
            }

            if ($previous !== null) {
                $gap = $previous['observed_at']->diffInSeconds(
                    $sample['observed_at'],
                    false,
                );

                if ($gap < 1 || $gap > $maximumGap) {
                    throw new RuntimeException(
                        'The saturation evidence sample timeline is invalid.',
                    );
                }

                $this->assertMonotonicCounters($previous, $sample);
            }

            $phase = $sample['phase'];
            $phaseBounds[$phase] ??= [
                'first' => $sample['observed_at'],
                'last' => $sample['observed_at'],
            ];
            $phaseBounds[$phase]['last'] = $sample['observed_at'];
            $samples[] = $sample;
            $previous = $sample;
            $previousPhaseIndex = $phaseIndex;
        }

        if ($previousPhaseIndex !== count($phases) - 1) {
            throw new RuntimeException(
                'The saturation evidence phases are incomplete.',
            );
        }

        $phaseDurations = [];
        $minimumDurations = $this->configuration
            ->phaseMinimumDurations();

        foreach ($phases as $phase) {
            if (! isset($phaseBounds[$phase])) {
                throw new RuntimeException(
                    'The saturation evidence phases are incomplete.',
                );
            }

            $duration = (int) $phaseBounds[$phase]['first']->diffInSeconds(
                $phaseBounds[$phase]['last'],
            );

            if ($duration < $minimumDurations[$phase]) {
                throw new RuntimeException(
                    'A saturation evidence phase is too short.',
                );
            }

            $phaseDurations[$phase] = $duration;
        }

        return [
            'release_sha' => $releaseSha,
            'sample_interval_seconds' => $sampleInterval,
            'started_at' => $samples[0]['observed_at'],
            'finished_at' => $samples[array_key_last($samples)]['observed_at'],
            'phase_durations_seconds' => $phaseDurations,
            'samples' => $samples,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sample(mixed $rawSample): array
    {
        if (! is_array($rawSample) || array_is_list($rawSample)) {
            throw new RuntimeException(
                'A saturation evidence sample is invalid.',
            );
        }

        $this->assertExactKeys($rawSample, [
            'observed_at',
            'phase',
            'database',
            'redis',
            'host',
            'application',
            'queues',
            'workers',
        ]);

        if (
            ! is_string($rawSample['phase'])
            || ! in_array(
                $rawSample['phase'],
                $this->configuration->phases(),
                true,
            )
        ) {
            throw new RuntimeException(
                'A saturation evidence sample phase is invalid.',
            );
        }

        $database = $this->object($rawSample['database'], [
            'connection_utilization_basis_points',
            'cpu_utilization_basis_points',
            'deadlocks_total',
        ]);
        $redis = $this->object($rawSample['redis'], [
            'memory_utilization_basis_points',
            'cpu_utilization_basis_points',
            'evicted_keys_total',
            'rejected_connections_total',
        ]);
        $host = $this->object($rawSample['host'], [
            'cpu_utilization_basis_points',
            'memory_utilization_basis_points',
        ]);
        $application = $this->object($rawSample['application'], [
            'http_requests_total',
            'http_server_errors_total',
            'jobs_processed_total',
            'jobs_failed_total',
        ]);

        return [
            'observed_at' => $this->timestamp($rawSample['observed_at']),
            'phase' => $rawSample['phase'],
            'database' => [
                'connection_utilization_basis_points' => (
                    $this->basisPoints(
                        $database['connection_utilization_basis_points'],
                    )
                ),
                'cpu_utilization_basis_points' => $this->basisPoints(
                    $database['cpu_utilization_basis_points'],
                ),
                'deadlocks_total' => $this->counter(
                    $database['deadlocks_total'],
                ),
            ],
            'redis' => [
                'memory_utilization_basis_points' => $this->basisPoints(
                    $redis['memory_utilization_basis_points'],
                ),
                'cpu_utilization_basis_points' => $this->basisPoints(
                    $redis['cpu_utilization_basis_points'],
                ),
                'evicted_keys_total' => $this->counter(
                    $redis['evicted_keys_total'],
                ),
                'rejected_connections_total' => $this->counter(
                    $redis['rejected_connections_total'],
                ),
            ],
            'host' => [
                'cpu_utilization_basis_points' => $this->basisPoints(
                    $host['cpu_utilization_basis_points'],
                ),
                'memory_utilization_basis_points' => $this->basisPoints(
                    $host['memory_utilization_basis_points'],
                ),
            ],
            'application' => [
                'http_requests_total' => $this->counter(
                    $application['http_requests_total'],
                ),
                'http_server_errors_total' => $this->counter(
                    $application['http_server_errors_total'],
                ),
                'jobs_processed_total' => $this->counter(
                    $application['jobs_processed_total'],
                ),
                'jobs_failed_total' => $this->counter(
                    $application['jobs_failed_total'],
                ),
            ],
            'queues' => $this->queues($rawSample['queues']),
            'workers' => $this->workers($rawSample['workers']),
        ];
    }

    /**
     * @return array<string, array{depth: int, oldest_job_age_milliseconds: int}>
     */
    private function queues(mixed $rawQueues): array
    {
        $queues = $this->namedObjects($rawQueues);
        $parsed = [];

        foreach ($this->configuration->queues() as $queue) {
            $value = $this->object($queues[$queue], [
                'depth',
                'oldest_job_age_milliseconds',
            ]);
            $parsed[$queue] = [
                'depth' => $this->counter($value['depth']),
                'oldest_job_age_milliseconds' => $this->counter(
                    $value['oldest_job_age_milliseconds'],
                ),
            ];
        }

        return $parsed;
    }

    /**
     * @return array<string, array{busy_utilization_basis_points: int, restarts_total: int}>
     */
    private function workers(mixed $rawWorkers): array
    {
        $workers = $this->namedObjects($rawWorkers);
        $parsed = [];

        foreach ($this->configuration->queues() as $queue) {
            $value = $this->object($workers[$queue], [
                'busy_utilization_basis_points',
                'restarts_total',
            ]);
            $parsed[$queue] = [
                'busy_utilization_basis_points' => $this->basisPoints(
                    $value['busy_utilization_basis_points'],
                ),
                'restarts_total' => $this->counter(
                    $value['restarts_total'],
                ),
            ];
        }

        return $parsed;
    }

    /**
     * @return array<string, mixed>
     */
    private function namedObjects(mixed $value): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new RuntimeException(
                'A saturation evidence queue or worker map is invalid.',
            );
        }

        $this->assertExactKeys($value, $this->configuration->queues());

        return $value;
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function object(mixed $value, array $keys): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new RuntimeException(
                'A saturation evidence metric object is invalid.',
            );
        }

        $this->assertExactKeys($value, $keys);

        return $value;
    }

    private function timestamp(mixed $value): CarbonImmutable
    {
        if (
            ! is_string($value)
            || preg_match(
                '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/',
                $value,
            ) !== 1
        ) {
            throw new RuntimeException(
                'A saturation evidence timestamp is invalid.',
            );
        }

        try {
            $timestamp = CarbonImmutable::createFromFormat(
                '!Y-m-d\TH:i:s\Z',
                $value,
                'UTC',
            );
        } catch (Throwable) {
            throw new RuntimeException(
                'A saturation evidence timestamp is invalid.',
            );
        }

        if (
            $timestamp === false
            || $timestamp->format('Y-m-d\TH:i:s\Z') !== $value
        ) {
            throw new RuntimeException(
                'A saturation evidence timestamp is invalid.',
            );
        }

        return $timestamp;
    }

    private function basisPoints(mixed $value): int
    {
        return $this->boundedInteger($value, 0, 10_000);
    }

    private function counter(mixed $value): int
    {
        return $this->boundedInteger($value, 0, PHP_INT_MAX);
    }

    private function boundedInteger(
        mixed $value,
        int $minimum,
        int $maximum,
    ): int {
        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new RuntimeException(
                'A saturation evidence integer metric is invalid.',
            );
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $sample
     */
    private function assertMonotonicCounters(
        array $previous,
        array $sample,
    ): void {
        foreach ([
            ['database', 'deadlocks_total'],
            ['redis', 'evicted_keys_total'],
            ['redis', 'rejected_connections_total'],
            ['application', 'http_requests_total'],
            ['application', 'http_server_errors_total'],
            ['application', 'jobs_processed_total'],
            ['application', 'jobs_failed_total'],
        ] as [$group, $metric]) {
            if ($sample[$group][$metric] < $previous[$group][$metric]) {
                throw new RuntimeException(
                    'A saturation evidence cumulative counter decreased.',
                );
            }
        }

        foreach ($this->configuration->queues() as $queue) {
            if (
                $sample['workers'][$queue]['restarts_total']
                    < $previous['workers'][$queue]['restarts_total']
            ) {
                throw new RuntimeException(
                    'A saturation evidence cumulative counter decreased.',
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $expected
     */
    private function assertExactKeys(array $value, array $expected): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($expected);

        if ($actual !== $expected) {
            throw new RuntimeException(
                'The saturation evidence object fields are invalid.',
            );
        }
    }
}
