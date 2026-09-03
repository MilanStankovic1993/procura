<?php

namespace App\Analysis\Metrics;

use App\Enums\Analyses\AnalysisPipelineStage;
use RuntimeException;

final class AnalysisPipelineMetricsConfiguration
{
    public function enabled(): bool
    {
        return config('performance.analysis_pipeline_metrics.enabled') === true;
    }

    public function retentionDays(): int
    {
        return $this->configuredInteger('retention_days', 1, 90);
    }

    public function purgeLimit(mixed $value): int
    {
        $maximum = $this->configuredInteger('maximum_purge_batch_size', 1, 5000);
        $default = $this->configuredInteger('default_purge_batch_size', 1, 5000);

        if ($default > $maximum) {
            throw new RuntimeException(
                'The default pipeline metric purge batch exceeds its maximum.',
            );
        }

        return $this->optionInteger(
            $value,
            $default,
            1,
            $maximum,
            'purge limit',
        );
    }

    public function windowMinutes(mixed $value): int
    {
        $maximum = $this->configuredInteger(
            'maximum_report_window_minutes',
            1,
            129_600,
        );
        $default = $this->configuredInteger(
            'default_report_window_minutes',
            1,
            129_600,
        );

        if ($default > $maximum) {
            throw new RuntimeException(
                'The pipeline metric report window is invalid.',
            );
        }

        return $this->optionInteger(
            $value,
            $default,
            1,
            $maximum,
            'report window',
        );
    }

    public function sampleLimit(mixed $value): int
    {
        $maximum = $this->configuredInteger('maximum_report_samples', 1, 10_000);
        $default = $this->configuredInteger('default_report_samples', 1, 10_000);

        if ($default > $maximum) {
            throw new RuntimeException(
                'The default pipeline metric sample limit exceeds its maximum.',
            );
        }

        return $this->optionInteger(
            $value,
            $default,
            1,
            $maximum,
            'sample limit',
        );
    }

    public function minimumSamples(mixed $value, int $sampleLimit): int
    {
        $maximum = $this->configuredInteger('maximum_minimum_samples', 1, 1000);
        $default = $this->configuredInteger('default_minimum_samples', 1, 1000);
        $minimum = $this->optionInteger(
            $value,
            $default,
            $default,
            $maximum,
            'minimum samples',
        );

        if ($default > $maximum || $minimum > $sampleLimit) {
            throw new RuntimeException(
                'The pipeline metric minimum sample size is invalid.',
            );
        }

        return $minimum;
    }

    public function expectedSamples(
        mixed $value,
        int $sampleLimit,
        int $minimumSamples,
        bool $required,
    ): ?int {
        if ($value === null || $value === '') {
            if ($required) {
                throw new RuntimeException(
                    'The expected pipeline metric sample count is required.',
                );
            }

            return null;
        }

        return $this->optionInteger(
            $value,
            $minimumSamples,
            $minimumSamples,
            $sampleLimit,
            'expected samples',
        );
    }

    /**
     * @return array{
     *     version: string,
     *     maximum_failure_rate_basis_points: int,
     *     maximum_total_p95_milliseconds: int,
     *     stages: array<string, int>
     * }
     */
    public function budgets(): array
    {
        $version = config('performance.analysis_pipeline_metrics.budgets.version');

        if (
            ! is_string($version)
            || preg_match('/\A[a-z0-9:_-]{1,128}\z/', $version) !== 1
        ) {
            throw new RuntimeException(
                'The pipeline metric budget version is invalid.',
            );
        }

        $stages = [];

        foreach (AnalysisPipelineStage::cases() as $stage) {
            $stages[$stage->value] = $this->configuredBudgetInteger(
                'maximum_'.$stage->value.'_p95_milliseconds',
                1,
                3_600_000,
            );
        }

        return [
            'version' => $version,
            'maximum_failure_rate_basis_points' => $this->configuredBudgetInteger(
                'maximum_failure_rate_basis_points',
                0,
                10_000,
            ),
            'maximum_total_p95_milliseconds' => $this->configuredBudgetInteger(
                'maximum_total_p95_milliseconds',
                1,
                3_600_000,
            ),
            'stages' => $stages,
        ];
    }

    public function assertValid(): void
    {
        $limit = $this->sampleLimit(null);

        $this->retentionDays();
        $this->purgeLimit(null);
        $this->windowMinutes(null);
        $this->minimumSamples(null, $limit);
        $this->budgets();
    }

    private function configuredInteger(
        string $key,
        int $minimum,
        int $maximum,
    ): int {
        $value = config("performance.analysis_pipeline_metrics.{$key}");

        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new RuntimeException(
                "The analysis pipeline metric {$key} setting is invalid.",
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
            "performance.analysis_pipeline_metrics.budgets.{$key}",
        );

        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new RuntimeException(
                "The analysis pipeline metric {$key} budget is invalid.",
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
                "The analysis pipeline metric {$label} option is invalid.",
            );
        }

        $integer = (int) $value;

        if ($integer < $minimum || $integer > $maximum) {
            throw new RuntimeException(
                "The analysis pipeline metric {$label} option is out of range.",
            );
        }

        return $integer;
    }
}
