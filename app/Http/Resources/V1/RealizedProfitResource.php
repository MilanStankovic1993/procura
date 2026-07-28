<?php

namespace App\Http\Resources\V1;

use App\Models\RealizedProfit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RealizedProfit */
class RealizedProfitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'owned_product_id' => $this->owned_product_id,
            'actual_purchase_id' => $this->actual_purchase_id,
            'actual_cost_snapshot_id' => $this->actual_cost_snapshot_id,
            'actual_sale_id' => $this->actual_sale_id,
            'run_number' => $this->run_number,
            'calculation_version' => $this->calculation_version,
            'input_hash' => $this->input_hash,
            'currency_code' => $this->currency_code,
            'purchase_price_minor' => $this->purchase_price_minor,
            'sale_price_minor' => $this->sale_price_minor,
            'actual_costs_minor' => $this->actual_costs_minor,
            'total_invested_minor' => $this->total_invested_minor,
            'net_profit_minor' => $this->net_profit_minor,
            'profit_margin_basis_points' => (
                $this->profit_margin_basis_points
            ),
            'return_on_invested_capital_basis_points' => (
                $this->return_on_invested_capital_basis_points
            ),
            'sale_duration_seconds' => $this->sale_duration_seconds,
            'reason_codes' => $this->reason_codes,
            'calculated_at' => $this->calculated_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
