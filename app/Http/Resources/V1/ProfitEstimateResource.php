<?php

namespace App\Http\Resources\V1;

use App\Models\ProfitEstimate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProfitEstimate */
class ProfitEstimateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'run_number' => $this->run_number,
            'price_estimate_id' => $this->price_estimate_id,
            'risk_assessment_id' => $this->risk_assessment_id,
            'cost_input_id' => $this->cost_input_id,
            'status' => $this->status->value,
            'calculation_version' => $this->calculation_version,
            'input_hash' => $this->input_hash,
            'calculation_at' => $this->calculation_at?->toIso8601String(),
            'currency_code' => $this->currency_code,
            'expected_sale_price_minor' => $this->expected_sale_price_minor,
            'purchase_price_minor' => $this->purchase_price_minor,
            'gross_margin_minor' => $this->gross_margin_minor,
            'known_costs_minor' => $this->known_costs_minor,
            'additional_costs_minor' => $this->additional_costs_minor,
            'total_cost_minor' => $this->total_cost_minor,
            'expected_net_profit_minor' => $this->expected_net_profit_minor,
            'profit_margin_basis_points' => (
                $this->profit_margin_basis_points
            ),
            'return_on_invested_capital_basis_points' => (
                $this->return_on_invested_capital_basis_points
            ),
            'confidence_basis_points' => $this->confidence_basis_points,
            'confidence_level' => $this->confidence_level->value,
            'unknown_count' => $this->unknown_count,
            'reason_codes' => $this->reason_codes,
            'confidence_components' => $this->confidence_components,
            'items' => $this->whenLoaded(
                'items',
                fn () => $this->items->map(static fn ($item): array => [
                    'id' => $item->getKey(),
                    'cost_input_item_id' => $item->cost_input_item_id,
                    'position' => $item->position,
                    'category' => $item->category,
                    'kind' => $item->kind->value,
                    'amount_minor' => $item->amount_minor,
                    'is_known' => $item->is_known,
                    'source' => $item->source_snapshot,
                ])->values()->all(),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
