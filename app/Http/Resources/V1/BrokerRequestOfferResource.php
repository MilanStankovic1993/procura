<?php

namespace App\Http\Resources\V1;

use App\Enums\BrokerRequests\BrokerRequestOfferStatus;
use App\Models\BrokerRequestOffer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BrokerRequestOffer */
class BrokerRequestOfferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $expired = $this->valid_until?->isPast() ?? true;

        return [
            'id' => $this->getKey(),
            'status' => $this->status->value,
            'supplier_display_name' => $this->supplier_display_name,
            'item_description' => $this->item_description,
            'condition' => $this->condition->value,
            'quantity' => $this->quantity,
            'unit_price_minor' => $this->unit_price_minor,
            'item_subtotal_minor' => $this->item_subtotal_minor,
            'shipping_cost_minor' => $this->shipping_cost_minor,
            'tax_duty_cost_minor' => $this->tax_duty_cost_minor,
            'other_cost_minor' => $this->other_cost_minor,
            'total_minor' => $this->total_minor,
            'commission_rule_version' => $this->commission_rule_version,
            'commission_rate_basis_points' => (
                $this->commission_rate_basis_points
            ),
            'commission_base_minor' => $this->commission_base_minor,
            'commission_amount_minor' => $this->commission_amount_minor,
            'payable_total_minor' => $this->payable_total_minor,
            'commission_rule_version' => $this->commission_rule_version,
            'commission_rate_basis_points' => (
                $this->commission_rate_basis_points
            ),
            'commission_base_minor' => $this->commission_base_minor,
            'commission_amount_minor' => $this->commission_amount_minor,
            'payable_total_minor' => $this->payable_total_minor,
            'currency_code' => $this->currency_code,
            'origin_country_code' => $this->origin_country_code,
            'estimated_delivery_date' => (
                $this->estimated_delivery_date?->toDateString()
            ),
            'valid_until' => $this->valid_until?->toIso8601String(),
            'is_expired' => $expired,
            'can_accept' => (
                $this->status === BrokerRequestOfferStatus::Presented
                && ! $expired
            ),
            'warranty_months' => $this->warranty_months,
            'return_policy_summary' => $this->return_policy_summary,
            'current_event_id' => $this->current_event_id,
            'event_sequence' => $this->event_sequence,
            'events' => BrokerRequestOfferEventResource::collection(
                $this->whenLoaded('events'),
            ),
            'presented_at' => $this->presented_at?->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
        ];
    }
}
