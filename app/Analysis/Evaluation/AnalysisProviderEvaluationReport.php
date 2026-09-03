<?php

namespace App\Analysis\Evaluation;

final readonly class AnalysisProviderEvaluationReport
{
    /**
     * @param  array<string, int>  $metrics
     * @param  array<string, int|string>  $budget
     * @param  list<string>  $failedChecks
     */
    public function __construct(
        public string $contractVersion,
        public string $environment,
        public string $releaseSha,
        public string $provider,
        public string $model,
        public string $promptVersion,
        public string $datasetVersion,
        public string $datasetSha256,
        public array $metrics,
        public array $budget,
        public array $failedChecks,
        public bool $eligibleForReleaseEvidence,
    ) {}

    public function passed(): bool
    {
        return $this->failedChecks === [];
    }

    public function releaseEvidence(): bool
    {
        return $this->passed()
            && $this->eligibleForReleaseEvidence
            && $this->environment === 'staging'
            && $this->provider !== 'fake';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->passed() ? 'passed' : 'failed',
            'release_evidence' => $this->releaseEvidence(),
            'report_contract_version' => $this->contractVersion,
            'budget_version' => $this->budget['version'],
            'environment' => $this->environment,
            'evidence_environment' => 'staging',
            'release_sha' => $this->releaseSha,
            'provider' => $this->provider,
            'model' => $this->model,
            'prompt_version' => $this->promptVersion,
            'dataset_version' => $this->datasetVersion,
            'dataset_sha256' => $this->datasetSha256,
            'metrics' => $this->metrics,
            'budget' => $this->budget,
            'failed_checks' => $this->failedChecks,
        ];
    }
}
