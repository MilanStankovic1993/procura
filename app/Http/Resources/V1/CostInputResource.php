<?php

namespace App\Http\Resources\V1;

use App\Models\CostInput;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CostInput */
class CostInputResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'run_number' => $this->run_number,
            'price_estimate_id' => $this->price_estimate_id,
            'risk_assessment_id' => $this->risk_assessment_id,
            'submitted_by_user_id' => $this->submitted_by_user_id,
            'input_version' => $this->input_version,
            'input_hash' => $this->input_hash,
            'currency_code' => $this->currency_code,
            'source_country_code' => $this->source_country_code,
            'target_country_code' => $this->target_country_code,
            'regional_compatibility_confirmed' => (
                $this->regional_compatibility_confirmed
            ),
            'known_count' => $this->known_count,
            'unknown_count' => $this->unknown_count,
            'items' => $this->whenLoaded(
                'items',
                fn () => $this->items->map(static fn ($item): array => [
                    'id' => $item->getKey(),
                    'position' => $item->position,
                    'category' => $item->category->value,
                    'amount_minor' => $item->amount_minor,
                    'is_known' => $item->is_known,
                    'source' => $item->source,
                ])->values()->all(),
            ),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
