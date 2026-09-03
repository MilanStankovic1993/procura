<?php

namespace App\BrokerRequests;

use App\Enums\BrokerRequests\BrokerPaymentCaseOutcome;
use App\Enums\BrokerRequests\BrokerPaymentCaseStatus;
use App\Models\BrokerPaymentCase;

final class BrokerPaymentCaseSnapshot
{
    /**
     * @return array<string, mixed>
     */
    public static function fromModel(
        BrokerPaymentCase $case,
        ?BrokerPaymentCaseStatus $status = null,
        ?BrokerPaymentCaseOutcome $outcome = null,
        ?int $resolvedAmountMinor = null,
    ): array {
        return [
            'type' => $case->type->value,
            'status' => ($status ?? $case->status)->value,
            'requested_amount_minor' => $case->requested_amount_minor,
            'resolved_amount_minor' => (
                $resolvedAmountMinor ?? $case->resolved_amount_minor
            ),
            'currency_code' => $case->currency_code,
            'resolution_outcome' => (
                $outcome ?? $case->resolution_outcome
            )?->value,
            'source_transaction_event_id' => (
                $case->source_transaction_event_id
            ),
            'external_case_reference' => $case->external_case_reference,
            'logical_case_hash' => $case->logical_case_hash,
            'opened_at' => $case->opened_at?->toIso8601String(),
            'resolved_at' => $case->resolved_at?->toIso8601String(),
            'cancelled_at' => $case->cancelled_at?->toIso8601String(),
        ];
    }
}
