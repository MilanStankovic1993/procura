<?php

namespace App\Http\Resources\V1;

use App\Models\RiskAssessment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RiskAssessment */
class RiskAssessmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'run_number' => $this->run_number,
            'product_match_id' => $this->product_match_id,
            'comparable_set_id' => $this->comparable_set_id,
            'price_estimate_id' => $this->price_estimate_id,
            'status' => $this->status->value,
            'evaluator_version' => $this->evaluator_version,
            'input_hash' => $this->input_hash,
            'calculation_at' => $this->calculation_at?->toIso8601String(),
            'score' => $this->score,
            'level' => $this->level->value,
            'confidence_basis_points' => $this->confidence_basis_points,
            'confidence_level' => $this->confidence_level->value,
            'signal_count' => $this->signal_count,
            'unknown_count' => $this->unknown_count,
            'reason_codes' => $this->reason_codes,
            'confidence_components' => $this->confidence_components,
            'verification_actions' => $this->verification_actions,
            'signals' => $this->whenLoaded(
                'signals',
                fn () => $this->signals->map(static fn ($signal): array => [
                    'id' => $signal->getKey(),
                    'position' => $signal->position,
                    'code' => $signal->code,
                    'category' => $signal->category->value,
                    'severity' => $signal->severity->value,
                    'is_unknown' => $signal->is_unknown,
                    'weight_points' => $signal->weight_points,
                    'score_contribution' => $signal->score_contribution,
                    'evidence' => $signal->evidence_snapshot,
                    'source' => $signal->source,
                    'confidence_basis_points' => $signal->confidence_basis_points,
                    'verification_action' => $signal->verification_action,
                ])->values()->all(),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
