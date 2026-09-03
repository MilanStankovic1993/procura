<?php

namespace App\Http\Resources\V1;

use App\Models\OwnedProductAssessment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OwnedProductAssessment */
class OwnedProductAssessmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'run_number' => $this->run_number,
            'owned_product_snapshot_id' => $this->owned_product_snapshot_id,
            'snapshot_sequence' => $this->snapshot?->sequence,
            'status' => $this->status->value,
            'matcher_status' => $this->matcher_status->value,
            'review_status' => $this->review_status->value,
            'method' => $this->method,
            'matcher_version' => $this->matcher_version,
            'evaluator_version' => $this->evaluator_version,
            'input_hash' => $this->input_hash,
            'confidence_basis_points' => $this->confidence_basis_points,
            'completeness_basis_points' => $this->completeness_basis_points,
            'product' => $this->when(
                $this->product_model_id !== null,
                fn (): array => [
                    'id' => $this->product_model_id,
                    'canonical_key' => $this->productModel?->canonical_key,
                    'category' => $this->productModel?->category?->name,
                    'brand' => $this->identified_brand_name,
                    'model' => $this->identified_model_name,
                    'model_number' => $this->productModel?->model_number,
                    'variant_id' => $this->product_variant_id,
                    'variant' => $this->identified_variant_name,
                ],
            ),
            'condition' => $this->condition->value,
            'included_accessories' => $this->included_accessories,
            'missing_accessories' => $this->missing_accessories,
            'defects' => $this->defects,
            'candidates' => $this->candidate_snapshot,
            'reason_codes' => $this->reason_codes,
            'unknown_facts' => $this->unknown_facts,
            'verification_actions' => $this->verification_actions,
            'assessed_by' => $this->whenLoaded(
                'assessedBy',
                fn (): ?array => $this->assessedBy === null
                    ? null
                    : [
                        'id' => $this->assessedBy->getKey(),
                        'name' => $this->assessedBy->name,
                    ],
            ),
            'assessed_at' => $this->assessed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
