<?php

namespace App\BrokerRequests;

use App\Enums\BrokerRequests\BrokerTransactionStatus;
use App\Models\BrokerTransaction;

final class BrokerTransactionSnapshot
{
    /**
     * @return array<string, mixed>
     */
    public static function fromModel(
        BrokerTransaction $transaction,
        ?BrokerTransactionStatus $status = null,
    ): array {
        return [
            'status' => ($status ?? $transaction->status)->value,
            'supplier_total_minor' => $transaction->supplier_total_minor,
            'commission_amount_minor' => (
                $transaction->commission_amount_minor
            ),
            'payable_total_minor' => $transaction->payable_total_minor,
            'currency_code' => $transaction->currency_code,
            'source_request_event_id' => (
                $transaction->source_request_event_id
            ),
            'source_offer_event_id' => $transaction->source_offer_event_id,
            'opened_at' => $transaction->opened_at?->toIso8601String(),
            'payment_confirmed_at' => (
                $transaction->payment_confirmed_at?->toIso8601String()
            ),
            'ordered_at' => $transaction->ordered_at?->toIso8601String(),
            'shipped_at' => $transaction->shipped_at?->toIso8601String(),
            'delivered_at' => $transaction->delivered_at?->toIso8601String(),
            'completed_at' => $transaction->completed_at?->toIso8601String(),
            'cancelled_at' => $transaction->cancelled_at?->toIso8601String(),
        ];
    }
}
