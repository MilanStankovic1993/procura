<?php

namespace App\Http\Resources\V1;

use App\Models\ComparableSet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ComparableSet */
class ComparableSetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'run_number' => $this->run_number,
            'product_match_id' => $this->product_match_id,
            'status' => $this->status->value,
            'selector_version' => $this->selector_version,
            'input_hash' => $this->input_hash,
            'target_country_code' => $this->target_country_code,
            'target_currency_code' => $this->target_currency_code,
            'candidate_count' => $this->candidate_count,
            'included_count' => $this->included_count,
            'excluded_count' => $this->excluded_count,
            'minimum_required' => $this->minimum_required,
            'reason_codes' => $this->reason_codes,
            'items' => $this->whenLoaded(
                'items',
                fn () => $this->items->map(static fn ($item): array => [
                    'id' => $item->getKey(),
                    'comparable_record_id' => $item->comparable_record_id,
                    'decision' => $item->decision->value,
                    'rank' => $item->rank,
                    'score_basis_points' => $item->score_basis_points,
                    'factor_scores' => $item->factor_scores,
                    'reason_codes' => $item->reason_codes,
                    'evidence' => $item->evidence_snapshot,
                ])->values()->all(),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
