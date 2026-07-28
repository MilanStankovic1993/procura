<?php

namespace App\Http\Resources\V1;

use App\Models\DealScore;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DealScore */
class DealScoreResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'run_number' => $this->run_number,
            'product_match_id' => $this->product_match_id,
            'price_estimate_id' => $this->price_estimate_id,
            'risk_assessment_id' => $this->risk_assessment_id,
            'profit_estimate_id' => $this->profit_estimate_id,
            'opportunity_input_id' => $this->opportunity_input_id,
            'logistics_assessment_id' => (
                $this->logistics_assessment_id
            ),
            'demand_assessment_id' => $this->demand_assessment_id,
            'status' => $this->status->value,
            'calculation_version' => $this->calculation_version,
            'input_hash' => $this->input_hash,
            'calculated_at' => $this->calculated_at?->toIso8601String(),
            'uncapped_score' => $this->uncapped_score,
            'uncapped_score_basis_points' => (
                $this->uncapped_score_basis_points
            ),
            'score' => $this->score,
            'score_basis_points' => $this->score_basis_points,
            'recommendation' => $this->recommendation->value,
            'confidence_basis_points' => $this->confidence_basis_points,
            'confidence_level' => $this->confidence_level->value,
            'unknown_count' => $this->unknown_count,
            'applicable_cap' => $this->applicable_cap,
            'cap_decisions' => $this->cap_decisions,
            'reason_codes' => $this->reason_codes,
            'confidence_components' => $this->confidence_components,
            'factors_increasing' => $this->factors_increasing,
            'factors_reducing' => $this->factors_reducing,
            'assumptions' => $this->assumptions,
            'verification_actions' => $this->verification_actions,
            'items' => $this->whenLoaded(
                'items',
                fn () => $this->items->map(static fn ($item): array => [
                    'id' => $item->getKey(),
                    'position' => $item->position,
                    'component' => $item->component->value,
                    'weight_basis_points' => $item->weight_basis_points,
                    'raw_value' => $item->raw_value,
                    'raw_value_unit' => $item->raw_value_unit,
                    'normalized_score_basis_points' => (
                        $item->normalized_score_basis_points
                    ),
                    'weighted_contribution_basis_points' => (
                        $item->weighted_contribution_basis_points
                    ),
                    'confidence_basis_points' => (
                        $item->confidence_basis_points
                    ),
                    'impact' => $item->impact->value,
                    'source' => $item->source_snapshot,
                ])->values()->all(),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
