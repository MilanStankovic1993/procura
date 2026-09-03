<?php

namespace App\Http\Resources\V1;

use App\Models\ProductMatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProductMatch */
class ProductMatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'run_number' => $this->run_number,
            'ai_analysis_id' => $this->ai_analysis_id,
            'status' => $this->status->value,
            'review_status' => $this->review_status->value,
            'method' => $this->method,
            'matcher_version' => $this->matcher_version,
            'input_hash' => $this->input_hash,
            'confidence_basis_points' => $this->confidence_basis_points,
            'product' => $this->when(
                $this->product_model_id !== null,
                fn (): array => [
                    'id' => $this->product_model_id,
                    'canonical_key' => $this->productModel?->canonical_key,
                    'brand' => $this->productModel?->brand?->name,
                    'model' => $this->productModel?->name,
                    'model_number' => $this->productModel?->model_number,
                    'category' => $this->productModel?->category?->name,
                    'variant_id' => $this->product_variant_id,
                    'variant' => $this->productVariant?->name,
                ],
            ),
            'candidates' => $this->candidate_snapshot,
            'reason_codes' => $this->reason_codes,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
