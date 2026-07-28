<?php

namespace App\Http\Resources\V1;

use App\Models\OwnedProductSnapshot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OwnedProductSnapshot */
class OwnedProductSnapshotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'sequence' => $this->sequence,
            'captured_at' => $this->captured_at?->toIso8601String(),
            'captured_by' => $this->whenLoaded(
                'capturedBy',
                fn (): ?array => $this->capturedBy === null
                    ? null
                    : [
                        'id' => $this->capturedBy->getKey(),
                        'name' => $this->capturedBy->name,
                    ],
            ),
            'category' => $this->whenLoaded(
                'category',
                fn (): ?array => $this->category === null
                    ? null
                    : [
                        'id' => $this->category->getKey(),
                        'name' => $this->category->name,
                        'slug' => $this->category->slug,
                    ],
            ),
            'brand_name' => $this->brand_name,
            'model_name' => $this->model_name,
            'condition' => $this->condition->value,
            'age_months' => $this->age_months,
            'accessories' => $this->accessories,
            'defects' => $this->defects,
            'purchase_history_known' => $this->purchase_history_known,
            'purchase_history' => $this->purchase_history,
            'target_continent_code' => $this->target_continent_code,
            'target_country_codes' => $this->target_country_codes,
            'cross_border_preference' => $this->cross_border_preference->value,
            'desired_sale_speed' => $this->desired_sale_speed->value,
            'status' => $this->status->value,
            'notes' => $this->notes,
            'content_hash' => $this->content_hash,
        ];
    }
}
