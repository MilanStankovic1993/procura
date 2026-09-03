<?php

namespace App\Http\Resources\V1;

use App\Models\OpportunityInput;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OpportunityInput */
class OpportunityInputResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'run_number' => $this->run_number,
            'comparable_set_id' => $this->comparable_set_id,
            'price_estimate_id' => $this->price_estimate_id,
            'risk_assessment_id' => $this->risk_assessment_id,
            'cost_input_id' => $this->cost_input_id,
            'profit_estimate_id' => $this->profit_estimate_id,
            'submitted_by_user_id' => $this->submitted_by_user_id,
            'input_version' => $this->input_version,
            'input_hash' => $this->input_hash,
            'source_country_code' => $this->source_country_code,
            'target_country_code' => $this->target_country_code,
            'known_count' => $this->known_count,
            'unknown_count' => $this->unknown_count,
            'items' => $this->whenLoaded(
                'items',
                fn () => $this->items->map(static fn ($item): array => [
                    'id' => $item->getKey(),
                    'component' => $item->component->value,
                    'position' => $item->position,
                    'code' => $item->code->value,
                    'value_type' => $item->value_type,
                    'value' => $item->value(),
                    'is_known' => $item->is_known,
                    'is_required' => $item->is_required,
                    'source' => $item->source,
                    'evidence' => $item->evidence_snapshot,
                ])->values()->all(),
            ),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
