<?php

namespace App\Http\Resources\V1;

use App\Models\OwnedProduct;
use App\Models\OwnedProductAssessment;
use App\OwnedProductAssessment\CurrentOwnedProductAssessmentResolver;
use App\OwnedProductAssessment\OwnedProductAssessmentEvidence;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OwnedProduct */
class OwnedProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'category' => new ProductCategoryResource($this->whenLoaded('category')),
            'brand_name' => $this->brand_name,
            'model_name' => $this->model_name,
            'condition' => $this->condition->value,
            'age_months' => $this->age_months,
            'accessories' => $this->accessories,
            'defects' => $this->defects,
            'purchase_history_known' => $this->purchase_history_known,
            'purchase_history' => $this->purchase_history,
            'target_continent_code' => $this->target_continent_code,
            'target_countries' => $this->whenLoaded(
                'targetCountries',
                fn () => $this->targetCountries->map(
                    static fn ($country): array => [
                        'code' => $country->code,
                        'name' => $country->name,
                        'currency_code' => $country->currency_code,
                    ],
                )->values(),
            ),
            'target_country_codes' => $this->whenLoaded(
                'targetCountries',
                fn () => $this->targetCountries->pluck('code')->values(),
            ),
            'cross_border_preference' => $this->cross_border_preference->value,
            'desired_sale_speed' => $this->desired_sale_speed->value,
            'status' => $this->status->value,
            'notes' => $this->notes,
            'image_count' => $this->whenCounted('images'),
            'snapshot_count' => $this->whenCounted('snapshots'),
            'assessment_count' => $this->whenCounted('assessments'),
            'images' => OwnedProductImageResource::collection(
                $this->whenLoaded('images'),
            ),
            'snapshots' => OwnedProductSnapshotResource::collection(
                $this->whenLoaded('snapshots'),
            ),
            'assessments' => OwnedProductAssessmentResource::collection(
                $this->whenLoaded('assessments'),
            ),
            'current_assessment' => $this->when(
                $this->relationLoaded('assessments')
                    && $this->relationLoaded('snapshots')
                    && $this->relationLoaded('images'),
                function (): ?OwnedProductAssessmentResource {
                    $assessment = $this->currentAssessment();

                    return $assessment === null
                        ? null
                        : new OwnedProductAssessmentResource($assessment);
                },
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    private function currentAssessment(): ?OwnedProductAssessment
    {
        $snapshot = $this->snapshots->first();

        if ($snapshot === null) {
            return null;
        }

        $imageEvidenceHash = OwnedProductAssessmentEvidence::imageHash(
            $this->images,
        );
        $resolver = app(CurrentOwnedProductAssessmentResolver::class);

        return $this->assessments->first(
            static fn (OwnedProductAssessment $assessment): bool => (
                $resolver->matches($assessment, $snapshot, $imageEvidenceHash)
            ),
        );
    }
}
