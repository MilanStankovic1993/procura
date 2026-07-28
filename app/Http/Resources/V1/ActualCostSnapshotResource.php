<?php

namespace App\Http\Resources\V1;

use App\Models\ActualCostSnapshot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ActualCostSnapshot */
class ActualCostSnapshotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'owned_product_id' => $this->owned_product_id,
            'previous_snapshot_id' => $this->previous_snapshot_id,
            'sequence' => $this->sequence,
            'reporting_currency_code' => $this->reporting_currency_code,
            'known_count' => $this->known_count,
            'unknown_count' => $this->unknown_count,
            'known_reporting_total_minor' => (
                $this->known_reporting_total_minor
            ),
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
            'items' => $this->whenLoaded(
                'items',
                fn (): array => ActualCostItemResource::collection(
                    $this->items,
                )->resolve($request),
            ),
            'recorded_at' => $this->recorded_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
