<?php

namespace App\Analysis\Monitoring;

use RuntimeException;
use Throwable;

final class AnalysisProviderMonitoringConfiguration
{
    public function enabled(): bool
    {
        return config('analyses.provider_monitoring.enabled') === true;
    }

    /**
     * @return array{
     *     stale_reservation_minutes: int,
     *     recent_window_minutes: int,
     *     uncertain_outcome_limit: int,
     *     rate_limit_limit: int,
     *     server_error_limit: int,
     *     budget_utilization_basis_points: int
     * }
     */
    public function thresholds(): array
    {
        return [
            'stale_reservation_minutes' => $this->integer(
                'stale_reservation_minutes',
                5,
                1440,
            ),
            'recent_window_minutes' => $this->integer(
                'recent_window_minutes',
                5,
                1440,
            ),
            'uncertain_outcome_limit' => $this->integer(
                'uncertain_outcome_limit',
                1,
                10_000,
            ),
            'rate_limit_limit' => $this->integer(
                'rate_limit_limit',
                1,
                10_000,
            ),
            'server_error_limit' => $this->integer(
                'server_error_limit',
                1,
                10_000,
            ),
            'budget_utilization_basis_points' => $this->integer(
                'budget_utilization_basis_points',
                1,
                10_000,
            ),
        ];
    }

    public function assertValid(): void
    {
        $this->thresholds();
    }

    public function isValid(): bool
    {
        try {
            $this->assertValid();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function integer(string $key, int $minimum, int $maximum): int
    {
        $value = config("analyses.provider_monitoring.{$key}");

        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new RuntimeException(
                "The analysis provider monitoring {$key} setting is invalid.",
            );
        }

        return $value;
    }
}
