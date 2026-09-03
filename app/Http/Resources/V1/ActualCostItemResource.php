<?php

namespace App\Http\Resources\V1;

use App\Models\ActualCostItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ActualCostItem */
class ActualCostItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'position' => $this->position,
            'category' => $this->category->value,
            'is_known' => $this->is_known,
            'source_amount_minor' => $this->source_amount_minor,
            'source_currency_code' => $this->source_currency_code,
            'reporting_amount_minor' => $this->reporting_amount_minor,
            'reporting_currency_code' => $this->reporting_currency_code,
            'conversion' => $this->is_known ? [
                'exchange_rate_id' => $this->exchange_rate_id,
                'direction' => $this->rate_direction?->value,
                'rate_value' => $this->rate_value,
                'effective_at' => $this->rate_effective_at?->toIso8601String(),
                'provider' => $this->rate_provider,
                'provider_reference' => $this->rate_provider_reference,
                'calculated_at' => (
                    $this->conversion_calculated_at?->toIso8601String()
                ),
            ] : null,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'evidence_kind' => $this->evidence_kind?->value,
            'evidence_reference' => $this->evidence_reference,
            'note' => $this->note,
            'evidence_hash' => $this->evidence_hash,
        ];
    }
}
