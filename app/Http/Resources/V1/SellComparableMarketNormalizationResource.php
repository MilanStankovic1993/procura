<?php

namespace App\Http\Resources\V1;

use App\Models\SellComparableMarketNormalization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SellComparableMarketNormalization */
final class SellComparableMarketNormalizationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'owned_product_id' => $this->owned_product_id,
            'owned_product_assessment_id' => (
                $this->owned_product_assessment_id
            ),
            'sell_comparable_record_id' => $this->sell_comparable_record_id,
            'calculation_version' => $this->calculation_version,
            'evidence_hash' => $this->evidence_hash,
            'compatibility_status' => $this->compatibility_status->value,
            'source_country_code' => $this->source_country_code,
            'target_country_code' => $this->target_country_code,
            'source_currency_code' => $this->source_currency_code,
            'target_currency_code' => $this->target_currency_code,
            'source_amount_minor' => $this->source_amount_minor,
            'converted_amount_minor' => $this->converted_amount_minor,
            'market_factor_basis_points' => $this->market_factor_basis_points,
            'market_adjusted_amount_minor' => $this->market_adjusted_amount_minor,
            'costs' => [
                'shipping_minor' => $this->shipping_minor,
                'import_duty_minor' => $this->import_duty_minor,
                'tax_minor' => $this->tax_minor,
                'other_cost_minor' => $this->other_cost_minor,
            ],
            'normalized_amount_minor' => $this->normalized_amount_minor,
            'exchange_rate' => [
                'id' => $this->exchange_rate_id,
                'direction' => $this->rate_direction?->value,
                'value' => $this->rate_value,
                'effective_at' => $this->rate_effective_at?->toIso8601String(),
                'provider' => $this->rate_provider,
                'provider_reference' => $this->rate_provider_reference,
            ],
            'evidence_reference' => $this->evidence_reference,
            'compatibility_note' => $this->compatibility_note,
            'reason_codes' => $this->reason_codes,
            'observed_at' => $this->observed_at?->toIso8601String(),
            'created_by_user_id' => $this->created_by_user_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
