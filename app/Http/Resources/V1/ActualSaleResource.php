<?php

namespace App\Http\Resources\V1;

use App\Models\ActualSale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ActualSale */
class ActualSaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'owned_product_id' => $this->owned_product_id,
            'sale_portfolio_entry_id' => $this->sale_portfolio_entry_id,
            'sale_portfolio_event_id' => $this->sale_portfolio_event_id,
            'previous_sale_id' => $this->previous_sale_id,
            'sequence' => $this->sequence,
            'outcome_type' => $this->outcome_type->value,
            'source_amount_minor' => $this->source_amount_minor,
            'source_currency_code' => $this->source_currency_code,
            'reporting_amount_minor' => $this->reporting_amount_minor,
            'reporting_currency_code' => $this->reporting_currency_code,
            'conversion' => $this->source_amount_minor === null ? null : [
                'exchange_rate_id' => $this->exchange_rate_id,
                'direction' => $this->rate_direction?->value,
                'rate_value' => $this->rate_value,
                'effective_at' => $this->rate_effective_at?->toIso8601String(),
                'provider' => $this->rate_provider,
                'provider_reference' => $this->rate_provider_reference,
                'calculated_at' => (
                    $this->conversion_calculated_at?->toIso8601String()
                ),
            ],
            'listed_at' => $this->listed_at?->toIso8601String(),
            'sale_duration_seconds' => $this->sale_duration_seconds,
            'evidence_kind' => $this->evidence_kind->value,
            'evidence_reference' => $this->evidence_reference,
            'reason_code' => $this->reason_code,
            'correction_reason' => $this->correction_reason,
            'note' => $this->note,
            'input_hash' => $this->input_hash,
            'actor' => $this->whenLoaded(
                'actor',
                fn (): array => [
                    'id' => $this->actor->getKey(),
                    'name' => $this->actor->name,
                ],
            ),
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'recorded_at' => $this->recorded_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
