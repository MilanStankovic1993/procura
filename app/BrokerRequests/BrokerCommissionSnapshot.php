<?php

namespace App\BrokerRequests;

use App\Enums\BrokerRequests\BrokerCommissionStatus;
use App\Models\BrokerCommission;

final class BrokerCommissionSnapshot
{
    /**
     * @return array<string, mixed>
     */
    public static function fromModel(
        BrokerCommission $commission,
        ?BrokerCommissionStatus $status = null,
    ): array {
        return [
            'status' => ($status ?? $commission->status)->value,
            'rule_version' => $commission->rule_version,
            'rate_basis_points' => $commission->rate_basis_points,
            'base_minor' => $commission->base_minor,
            'amount_minor' => $commission->amount_minor,
            'currency_code' => $commission->currency_code,
            'source_transaction_event_id' => (
                $commission->source_transaction_event_id
            ),
            'recorded_at' => $commission->recorded_at?->toIso8601String(),
            'earned_at' => $commission->earned_at?->toIso8601String(),
            'settled_at' => $commission->settled_at?->toIso8601String(),
            'waived_at' => $commission->waived_at?->toIso8601String(),
        ];
    }
}
