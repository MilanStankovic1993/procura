<?php

namespace App\Http\Resources\V1;

use App\Models\ActualPurchase;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ActualPurchase */
class ActualPurchaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'owned_product_id' => $this->owned_product_id,
            'previous_purchase_id' => $this->previous_purchase_id,
            'sequence' => $this->sequence,
            'source_amount_minor' => $this->source_amount_minor,
            'source_currency_code' => $this->source_currency_code,
            'reporting_amount_minor' => $this->reporting_amount_minor,
            'reporting_currency_code' => $this->reporting_currency_code,
            'conversion' => [
                'exchange_rate_id' => $this->exchange_rate_id,
                'direction' => $this->rate_direction->value,
                'rate_value' => $this->rate_value,
                'effective_at' => $this->rate_effective_at?->toIso8601String(),
                'provider' => $this->rate_provider,
                'provider_reference' => $this->rate_provider_reference,
                'calculated_at' => (
                    $this->conversion_calculated_at?->toIso8601String()
                ),
            ],
            'evidence_kind' => $this->evidence_kind->value,
            'evidence_reference' => $this->evidence_reference,
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
