<?php

namespace App\BrokerRequests;

use App\Enums\BrokerRequests\BrokerRequestOfferStatus;
use App\Models\BrokerRequestOffer;

final class BrokerRequestOfferSnapshot
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function fromInput(
        array $input,
        BrokerRequestOfferStatus $status,
    ): array {
        return [
            'status' => $status->value,
            ...$input,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function fromModel(
        BrokerRequestOffer $offer,
        ?BrokerRequestOfferStatus $status = null,
    ): array {
        return [
            'status' => ($status ?? $offer->status)->value,
            'supplier_display_name' => $offer->supplier_display_name,
            'supplier_reference' => $offer->supplier_reference,
            'item_description' => $offer->item_description,
            'condition' => $offer->condition->value,
            'quantity' => $offer->quantity,
            'unit_price_minor' => $offer->unit_price_minor,
            'item_subtotal_minor' => $offer->item_subtotal_minor,
            'shipping_cost_minor' => $offer->shipping_cost_minor,
            'tax_duty_cost_minor' => $offer->tax_duty_cost_minor,
            'other_cost_minor' => $offer->other_cost_minor,
            'total_minor' => $offer->total_minor,
            'commission_rule_version' => $offer->commission_rule_version,
            'commission_rate_basis_points' => (
                $offer->commission_rate_basis_points
            ),
            'commission_base_minor' => $offer->commission_base_minor,
            'commission_amount_minor' => $offer->commission_amount_minor,
            'payable_total_minor' => $offer->payable_total_minor,
            'currency_code' => $offer->currency_code,
            'origin_country_code' => $offer->origin_country_code,
            'estimated_delivery_date' => (
                $offer->estimated_delivery_date?->toDateString()
            ),
            'valid_until' => $offer->valid_until?->toIso8601String(),
            'warranty_months' => $offer->warranty_months,
            'return_policy_summary' => $offer->return_policy_summary,
        ];
    }
}
