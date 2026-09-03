<?php

namespace App\Analysis\Monitoring\Data;

use Carbon\CarbonImmutable;

final readonly class AnalysisProviderMonitoringReport
{
    /**
     * @param  array{
     *     open_circuits: int,
     *     half_open_circuits: int,
     *     stale_reservations: int,
     *     uncertain_outcomes_recent: int,
     *     rate_limits_recent: int,
     *     server_errors_recent: int,
     *     cost_overruns_recent: int,
     *     budget_scopes_near_limit: int
     * }  $counts
     * @param  array<string, int>  $thresholds
     */
    public function __construct(
        public CarbonImmutable $checkedAt,
        public array $counts,
        public array $thresholds,
    ) {}

    /** @return array<string, bool> */
    public function attentionSignals(): array
    {
        return [
            'open_circuits' => $this->counts['open_circuits'] > 0,
            'half_open_circuits' => $this->counts['half_open_circuits'] > 0,
            'stale_reservations' => $this->counts['stale_reservations'] > 0,
            'uncertain_outcomes_recent' => $this->counts['uncertain_outcomes_recent']
                >= $this->thresholds['uncertain_outcome_limit'],
            'rate_limits_recent' => $this->counts['rate_limits_recent']
                >= $this->thresholds['rate_limit_limit'],
            'server_errors_recent' => $this->counts['server_errors_recent']
                >= $this->thresholds['server_error_limit'],
            'cost_overruns_recent' => $this->counts['cost_overruns_recent'] > 0,
            'budget_scopes_near_limit' => $this->counts['budget_scopes_near_limit'] > 0,
        ];
    }

    public function attentionCount(): int
    {
        return count(array_filter($this->attentionSignals()));
    }

    public function status(): string
    {
        return $this->attentionCount() > 0
            ? 'attention_required'
            : 'clear';
    }

    /**
     * @return array{
     *     status: string,
     *     timestamp: string,
     *     attention_count: int,
     *     counts: array<string, int>,
     *     thresholds: array<string, int>
     * }
     */
    public function operatorPayload(): array
    {
        return [
            'status' => $this->status(),
            'timestamp' => $this->checkedAt->toIso8601String(),
            'attention_count' => $this->attentionCount(),
            'counts' => $this->counts,
            'thresholds' => $this->thresholds,
        ];
    }
}
