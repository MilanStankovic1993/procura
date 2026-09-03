<?php

namespace App\Http\Resources\V1;

use App\Models\OutcomeEstimateAttribution;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OutcomeEstimateAttribution */
class OutcomeEstimateAttributionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'owned_product_id' => $this->owned_product_id,
            'realized_profit_id' => $this->realized_profit_id,
            'analysis_id' => $this->analysis_id,
            'profit_estimate_id' => $this->profit_estimate_id,
            'previous_attribution_id' => $this->previous_attribution_id,
            'sequence' => $this->sequence,
            'reason_code' => $this->reason_code,
            'evidence_kind' => $this->evidence_kind->value,
            'evidence_reference' => $this->evidence_reference,
            'correction_reason' => $this->correction_reason,
            'note' => $this->note,
            'input_hash' => $this->input_hash,
            'actor' => $this->whenLoaded('actor', fn (): array => [
                'id' => $this->actor->getKey(),
                'name' => $this->actor->name,
            ]),
            'estimate_source' => $this->whenLoaded(
                'analysis',
                fn (): array => [
                    'analysis_id' => $this->analysis->getKey(),
                    'listing_id' => $this->analysis->listing_id,
                    'listing_title' => $this->analysis->relationLoaded('listing')
                        ? $this->analysis->listing?->title
                        : null,
                ],
            ),
            'attributed_at' => $this->attributed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
