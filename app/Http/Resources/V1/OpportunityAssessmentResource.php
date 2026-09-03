<?php

namespace App\Http\Resources\V1;

use App\Models\OpportunityAssessment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OpportunityAssessment */
class OpportunityAssessmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'run_number' => $this->run_number,
            'opportunity_input_id' => $this->opportunity_input_id,
            'profit_estimate_id' => $this->profit_estimate_id,
            'component' => $this->component->value,
            'status' => $this->status->value,
            'evaluator_version' => $this->evaluator_version,
            'input_hash' => $this->input_hash,
            'calculated_at' => $this->calculated_at?->toIso8601String(),
            'score' => $this->score,
            'confidence_basis_points' => $this->confidence_basis_points,
            'confidence_level' => $this->confidence_level->value,
            'unknown_count' => $this->unknown_count,
            'reason_codes' => $this->reason_codes,
            'confidence_components' => $this->confidence_components,
            'items' => $this->whenLoaded(
                'items',
                fn () => $this->items->map(static fn ($item): array => [
                    'id' => $item->getKey(),
                    'opportunity_input_item_id' => (
                        $item->opportunity_input_item_id
                    ),
                    'position' => $item->position,
                    'code' => $item->code,
                    'maximum_points' => $item->maximum_points,
                    'score_contribution' => $item->score_contribution,
                    'is_known' => $item->is_known,
                    'source' => $item->source_snapshot,
                ])->values()->all(),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
