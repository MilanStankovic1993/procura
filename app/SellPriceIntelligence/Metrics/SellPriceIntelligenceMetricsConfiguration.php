<?php

namespace App\SellPriceIntelligence\Metrics;

use App\Enums\Sell\SellPriceIntelligenceMetricStage;
use RuntimeException;

final class SellPriceIntelligenceMetricsConfiguration
{
    public function enabled(): bool
    {
        return config('performance.sell_price_intelligence_metrics.enabled') === true;
    }

    public function retentionDays(): int
    {
        return $this->configuredInteger('retention_days', 1, 90);
    }

    public function minimumScopesPerOperation(): int
    {
        return $this->configuredInteger('minimum_scopes_per_operation', 2, 100);
    }

    public function purgeLimit(mixed $value): int
    {
        $maximum = $this->configuredInteger('maximum_purge_batch_size', 1, 5000);
        $default = $this->configuredInteger('default_purge_batch_size', 1, 5000);

        if ($default > $maximum) {
            throw new RuntimeException(
                'The default Sell metric purge batch exceeds its maximum.',
            );
        }

        return $this->optionInteger($value, $default, 1, $maximum, 'purge limit');
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
            throw new RuntimeException('The Sell metric report window is invalid.');
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
                'The default Sell metric sample limit exceeds its maximum.',
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
            throw new RuntimeException('The Sell metric minimum sample size is invalid.');
        }

        return $minimum;
    }

    public function expectedOperations(
        mixed $value,
        int $sampleLimit,
        int $minimumSamples,
        bool $required,
    ): ?int {
        return $this->expectedValue(
            $value,
            $minimumSamples,
            $sampleLimit,
            $required,
            'operation count',
        );
    }

    public function expectedScopes(
        mixed $value,
        int $expectedOperations,
        bool $required,
    ): ?int {
        $minimum = $expectedOperations * $this->minimumScopesPerOperation();

        return $this->expectedValue(
            $value,
            $minimum,
            1_000_000,
            $required,
            'scope count',
        );
    }

    /** @return array{version: string, stages: array<string, int>, maximum_total_p95_milliseconds: int} */
    public function budgets(): array
    {
        $version = $this->configuredVersion('budgets.version');
        $stages = [];

        foreach (SellPriceIntelligenceMetricStage::cases() as $stage) {
            $stages[$stage->value] = $this->configuredBudgetInteger(
                'maximum_'.$stage->value.'_p95_milliseconds',
                1,
                3_600_000,
            );
        }

        return [
            'version' => $version,
            'stages' => $stages,
            'maximum_total_p95_milliseconds' => $this->configuredBudgetInteger(
                'maximum_total_p95_milliseconds',
                1,
                3_600_000,
            ),
        ];
    }

    public function metricsVersion(): string
    {
        return $this->configuredVersion('metrics_version');
    }

    public function assertValid(): void
    {
        $limit = $this->sampleLimit(null);

        $this->metricsVersion();
        $this->retentionDays();
        $this->minimumScopesPerOperation();
        $this->purgeLimit(null);
        $this->windowMinutes(null);
        $this->minimumSamples(null, $limit);
        $this->budgets();
    }

    private function expectedValue(
        mixed $value,
        int $minimum,
        int $maximum,
        bool $required,
        string $label,
    ): ?int {
        if ($value === null || $value === '') {
            if ($required) {
                throw new RuntimeException(
                    "The expected Sell metric {$label} is required.",
                );
            }

            return null;
        }

        return $this->optionInteger($value, $minimum, $minimum, $maximum, $label);
    }

    private function configuredVersion(string $key): string
    {
        $value = config("performance.sell_price_intelligence_metrics.{$key}");

        if (
            ! is_string($value)
            || preg_match('/\A[a-z0-9:_-]{1,128}\z/', $value) !== 1
        ) {
            throw new RuntimeException(
                "The Sell price-intelligence metric {$key} is invalid.",
            );
        }

        return $value;
    }

    private function configuredInteger(
        string $key,
        int $minimum,
        int $maximum,
    ): int {
        $value = config("performance.sell_price_intelligence_metrics.{$key}");

        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new RuntimeException(
                "The Sell price-intelligence metric {$key} setting is invalid.",
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
            "performance.sell_price_intelligence_metrics.budgets.{$key}",
        );

        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new RuntimeException(
                "The Sell price-intelligence metric {$key} budget is invalid.",
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

        if (! is_string($value) || preg_match('/\A[0-9]+\z/', $value) !== 1) {
            throw new RuntimeException(
                "The Sell price-intelligence metric {$label} option is invalid.",
            );
        }

        $integer = (int) $value;

        if ($integer < $minimum || $integer > $maximum) {
            throw new RuntimeException(
                "The Sell price-intelligence metric {$label} option is out of range.",
            );
        }

        return $integer;
    }
}
