<?php

namespace App\Operations\Data;

use Carbon\CarbonImmutable;

final readonly class ProductionPreflightReport
{
    /**
     * @param  list<ProductionPreflightCheck>  $checks
     */
    public function __construct(
        public string $environment,
        public CarbonImmutable $checkedAt,
        public array $checks,
    ) {}

    public function deploymentReady(): bool
    {
        foreach ($this->checks as $check) {
            if ($check->blocksDeployment()) {
                return false;
            }
        }

        return true;
    }

    public function launchReady(): bool
    {
        foreach ($this->checks as $check) {
            if ($check->blocksDeployment() || $check->requiresReview()) {
                return false;
            }
        }

        return true;
    }

    public function status(): string
    {
        return match (true) {
            ! $this->deploymentReady() => 'blocked',
            ! $this->launchReady() => 'review_required',
            default => 'ready',
        };
    }

    /**
     * @return array{
     *     status: string,
     *     environment: string,
     *     timestamp: string,
     *     summary: array{passed: int, warnings: int, failed: int},
     *     checks: array<string, array{status: string, message: string}>
     * }
     */
    public function operatorPayload(): array
    {
        $summary = [
            'passed' => 0,
            'warnings' => 0,
            'failed' => 0,
        ];
        $checks = [];

        foreach ($this->checks as $check) {
            $checks[$check->key] = $check->payload();

            match ($check->status) {
                ProductionPreflightCheck::PASS => $summary['passed']++,
                ProductionPreflightCheck::WARNING => $summary['warnings']++,
                default => $summary['failed']++,
            };
        }

        return [
            'status' => $this->status(),
            'environment' => $this->environment,
            'timestamp' => $this->checkedAt->toIso8601String(),
            'summary' => $summary,
            'checks' => $checks,
        ];
    }
}
