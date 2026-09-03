<?php

namespace App\Http\Resources\V1;

use App\Models\Analysis;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Analysis */
class EstimateAccuracyCandidateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $estimate = $this->currentProfitEstimate;

        return [
            'analysis_id' => $this->getKey(),
            'profit_estimate_id' => $estimate->getKey(),
            'listing' => [
                'id' => $this->listing->getKey(),
                'title' => $this->listing->title,
                'marketplace_name' => $this->listing->marketplace_name,
            ],
            'currency_code' => $estimate->currency_code,
            'expected_purchase_price_minor' => (
                $estimate->purchase_price_minor
            ),
            'expected_additional_costs_minor' => (
                $estimate->additional_costs_minor
            ),
            'expected_sale_price_minor' => (
                $estimate->expected_sale_price_minor
            ),
            'expected_net_profit_minor' => (
                $estimate->expected_net_profit_minor
            ),
            'calculation_at' => (
                $estimate->calculation_at?->toIso8601String()
            ),
        ];
    }
}
