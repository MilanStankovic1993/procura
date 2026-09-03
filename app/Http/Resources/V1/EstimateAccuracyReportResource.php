<?php

namespace App\Http\Resources\V1;

use App\Models\EstimateAccuracyReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EstimateAccuracyReport */
class EstimateAccuracyReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $metrics = [];

        foreach ([
            'purchase_price',
            'additional_costs',
            'sale_price',
            'net_profit',
        ] as $metric) {
            $metrics[$metric] = [
                'source_expected_minor' => (
                    $this->{"source_expected_{$metric}_minor"}
                ),
                'expected_minor' => $this->{"expected_{$metric}_minor"},
                'actual_minor' => $this->{"actual_{$metric}_minor"},
                'signed_error_minor' => (
                    $this->{"{$metric}_signed_error_minor"}
                ),
                'absolute_error_minor' => (
                    $this->{"{$metric}_absolute_error_minor"}
                ),
                'signed_error_basis_points' => (
                    $this->{"{$metric}_signed_error_basis_points"}
                ),
                'absolute_percentage_error_basis_points' => (
                    $this->{
                        "{$metric}_absolute_percentage_error_basis_points"
                    }
                ),
            ];
        }

        return [
            'id' => $this->getKey(),
            'owned_product_id' => $this->owned_product_id,
            'outcome_estimate_attribution_id' => (
                $this->outcome_estimate_attribution_id
            ),
            'realized_profit_id' => $this->realized_profit_id,
            'analysis_id' => $this->analysis_id,
            'profit_estimate_id' => $this->profit_estimate_id,
            'previous_report_id' => $this->previous_report_id,
            'sequence' => $this->sequence,
            'status' => $this->status->value,
            'calculation_version' => $this->calculation_version,
            'input_hash' => $this->input_hash,
            'source_currency_code' => $this->source_currency_code,
            'reporting_currency_code' => $this->reporting_currency_code,
            'conversion' => [
                'exchange_rate_id' => $this->exchange_rate_id,
                'direction' => $this->rate_direction->value,
                'rate_value' => $this->rate_value,
                'effective_at' => $this->rate_effective_at?->toIso8601String(),
                'provider' => $this->rate_provider,
                'provider_reference' => $this->rate_provider_reference,
                'calculated_at' => (
                    $this->conversion_calculated_at?->toIso8601String()
                ),
            ],
            'metrics' => $metrics,
            'duration' => [
                'expected_seconds' => $this->expected_sale_duration_seconds,
                'actual_seconds' => $this->actual_sale_duration_seconds,
                'signed_error_seconds' => (
                    $this->sale_duration_signed_error_seconds
                ),
                'absolute_error_seconds' => (
                    $this->sale_duration_absolute_error_seconds
                ),
            ],
            'reason_codes' => $this->reason_codes,
            'unavailable_metrics' => $this->unavailable_metrics,
            'calculated_at' => $this->calculated_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
