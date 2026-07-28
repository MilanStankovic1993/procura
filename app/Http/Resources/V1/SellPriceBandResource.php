<?php

namespace App\Http\Resources\V1;

use App\Models\SellPriceBand;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SellPriceBand */
class SellPriceBandResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'owned_product_assessment_id' => $this->owned_product_assessment_id,
            'sell_comparable_selection_id' => (
                $this->sell_comparable_selection_id
            ),
            'run_number' => $this->run_number,
            'status' => $this->status->value,
            'algorithm_version' => $this->algorithm_version,
            'input_hash' => $this->input_hash,
            'calculated_at' => $this->calculated_at?->toIso8601String(),
            'target_country_code' => $this->target_country_code,
            'target_currency_code' => $this->target_currency_code,
            'input_count' => $this->input_count,
            'included_count' => $this->included_count,
            'outlier_count' => $this->outlier_count,
            'bands' => [
                'quick_sale' => [
                    'low_minor' => $this->quick_sale_low_minor,
                    'high_minor' => $this->quick_sale_high_minor,
                ],
                'recommended' => [
                    'low_minor' => $this->recommended_low_minor,
                    'high_minor' => $this->recommended_high_minor,
                ],
                'ambitious' => [
                    'low_minor' => $this->ambitious_low_minor,
                    'high_minor' => $this->ambitious_high_minor,
                ],
            ],
            'statistics' => [
                'median_minor' => $this->median_minor,
                'weighted_median_minor' => $this->weighted_median_minor,
                'q1_minor' => $this->q1_minor,
                'q3_minor' => $this->q3_minor,
                'mad_minor' => $this->mad_minor,
                'dispersion_basis_points' => $this->dispersion_basis_points,
            ],
            'confidence_basis_points' => $this->confidence_basis_points,
            'confidence_level' => $this->confidence_level?->value,
            'completeness_basis_points' => $this->completeness_basis_points,
            'confidence_components' => $this->confidence_components,
            'reason_codes' => $this->reason_codes,
            'unknown_facts' => $this->unknown_facts,
            'verification_actions' => $this->verification_actions,
            'selection' => new SellComparableSelectionResource(
                $this->whenLoaded('selection'),
            ),
            'items' => $this->whenLoaded(
                'items',
                fn () => $this->items->map(static fn ($item): array => [
                    'id' => $item->getKey(),
                    'sell_comparable_selection_item_id' => (
                        $item->sell_comparable_selection_item_id
                    ),
                    'sell_comparable_record_id' => (
                        $item->sell_comparable_record_id
                    ),
                    'position' => $item->position,
                    'decision' => $item->decision->value,
                    'asking_price_minor' => $item->asking_price_minor,
                    'currency_code' => $item->currency_code,
                    'weight_basis_points' => $item->weight_basis_points,
                    'reason_codes' => $item->reason_codes,
                    'evidence' => $item->evidence_snapshot,
                ])->values()->all(),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
