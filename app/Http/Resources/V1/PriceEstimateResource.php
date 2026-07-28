<?php

namespace App\Http\Resources\V1;

use App\Models\PriceEstimate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PriceEstimate */
class PriceEstimateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'run_number' => $this->run_number,
            'comparable_set_id' => $this->comparable_set_id,
            'status' => $this->status->value,
            'algorithm_version' => $this->algorithm_version,
            'rate_resolver_version' => $this->rate_resolver_version,
            'input_hash' => $this->input_hash,
            'calculation_at' => $this->calculation_at?->toIso8601String(),
            'target_country_code' => $this->target_country_code,
            'target_currency_code' => $this->target_currency_code,
            'input_count' => $this->input_count,
            'included_count' => $this->included_count,
            'outlier_count' => $this->outlier_count,
            'unresolved_count' => $this->unresolved_count,
            'estimate_low_minor' => $this->estimate_low_minor,
            'estimate_minor' => $this->estimate_minor,
            'estimate_high_minor' => $this->estimate_high_minor,
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
            'confidence_components' => $this->confidence_components,
            'reason_codes' => $this->reason_codes,
            'items' => $this->whenLoaded(
                'items',
                fn () => $this->items->map(static fn ($item): array => [
                    'id' => $item->getKey(),
                    'comparable_set_item_id' => $item->comparable_set_item_id,
                    'comparable_record_id' => $item->comparable_record_id,
                    'decision' => $item->decision->value,
                    'position' => $item->position,
                    'original_amount_minor' => $item->original_amount_minor,
                    'original_currency_code' => $item->original_currency_code,
                    'target_amount_minor' => $item->target_amount_minor,
                    'target_currency_code' => $item->target_currency_code,
                    'weight_basis_points' => $item->weight_basis_points,
                    'exchange_rate_id' => $item->exchange_rate_id,
                    'rate_direction' => $item->rate_direction->value,
                    'rate_value' => $item->rate_value,
                    'rate_effective_at' => $item->rate_effective_at?->toIso8601String(),
                    'rate_provider' => $item->rate_provider,
                    'rate_provider_reference' => $item->rate_provider_reference,
                    'reason_codes' => $item->reason_codes,
                    'evidence' => $item->evidence_snapshot,
                ])->values()->all(),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
