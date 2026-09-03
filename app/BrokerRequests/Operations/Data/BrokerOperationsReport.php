<?php

namespace App\BrokerRequests\Operations\Data;

use Carbon\CarbonImmutable;

final readonly class BrokerOperationsReport
{
    /**
     * @param  array{
     *     requests_aging: int,
     *     requests_past_needed_by: int,
     *     offers_expired: int,
     *     transactions_aging: int,
     *     commissions_aging: int,
     *     reports_overdue_purge: int,
     *     payment_cases_aging: int
     * }  $counts
     * @param  array{
     *     request_age_hours: int,
     *     offer_expiry_grace_hours: int,
     *     transaction_age_hours: int,
     *     commission_age_hours: int,
     *     report_purge_grace_hours: int,
     *     payment_case_age_hours: int
     * }  $thresholds
     */
    public function __construct(
        public CarbonImmutable $checkedAt,
        public array $counts,
        public array $thresholds,
    ) {}

    public function attentionCount(): int
    {
        return array_sum($this->counts);
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
