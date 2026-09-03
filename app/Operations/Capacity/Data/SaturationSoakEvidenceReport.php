<?php

namespace App\Operations\Capacity\Data;

use Carbon\CarbonImmutable;

final readonly class SaturationSoakEvidenceReport
{
    /**
     * @param  array<string, int>  $phaseDurationsSeconds
     * @param  array<string, mixed>  $metrics
     * @param  array<string, mixed>  $budget
     * @param  list<string>  $failedChecks
     */
    public function __construct(
        public string $reportContractVersion,
        public string $environment,
        public string $releaseSha,
        public int $sampleIntervalSeconds,
        public int $sampleCount,
        public CarbonImmutable $startedAt,
        public CarbonImmutable $finishedAt,
        public array $phaseDurationsSeconds,
        public array $metrics,
        public array $budget,
        public array $failedChecks,
    ) {}

    public function passed(): bool
    {
        return $this->failedChecks === [];
    }

    public function releaseEvidence(): bool
    {
        return $this->passed() && $this->environment === 'staging';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->passed() ? 'passed' : 'failed',
            'release_evidence' => $this->releaseEvidence(),
            'report_contract_version' => $this->reportContractVersion,
            'budget_version' => $this->budget['version'],
            'environment' => $this->environment,
            'evidence_environment' => 'staging',
            'release_sha' => $this->releaseSha,
            'started_at' => $this->startedAt->format(
                'Y-m-d\TH:i:s\Z',
            ),
            'finished_at' => $this->finishedAt->format(
                'Y-m-d\TH:i:s\Z',
            ),
            'sample_interval_seconds' => $this->sampleIntervalSeconds,
            'sample_count' => $this->sampleCount,
            'phase_durations_seconds' => $this->phaseDurationsSeconds,
            'metrics' => $this->metrics,
            'budget' => $this->budget,
            'failed_checks' => $this->failedChecks,
        ];
    }
}
