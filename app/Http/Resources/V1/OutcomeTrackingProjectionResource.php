<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OutcomeTrackingProjectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'purchases' => ActualPurchaseResource::collection(
                $this->resource['purchases'],
            ),
            'current_purchase_id' => (
                $this->resource['current_purchase_id']
            ),
            'cost_snapshots' => ActualCostSnapshotResource::collection(
                $this->resource['cost_snapshots'],
            ),
            'current_cost_snapshot_id' => (
                $this->resource['current_cost_snapshot_id']
            ),
            'sales' => ActualSaleResource::collection(
                $this->resource['sales'],
            ),
            'current_sold_sale_id' => (
                $this->resource['current_sold_sale_id']
            ),
            'sale_portfolio_entries' => (
                SalePortfolioEntryResource::collection(
                    $this->resource['sale_portfolio_entries'],
                )
            ),
            'realized_profits' => RealizedProfitResource::collection(
                $this->resource['realized_profits'],
            ),
            'current_realized_profit_id' => (
                $this->resource['current_realized_profit_id']
            ),
            'estimate_attributions' => (
                OutcomeEstimateAttributionResource::collection(
                    $this->resource['estimate_attributions'],
                )
            ),
            'current_estimate_attribution_id' => (
                $this->resource['current_estimate_attribution_id']
            ),
            'estimate_accuracy_reports' => (
                EstimateAccuracyReportResource::collection(
                    $this->resource['estimate_accuracy_reports'],
                )
            ),
            'current_estimate_accuracy_report_id' => (
                $this->resource['current_estimate_accuracy_report_id']
            ),
            'accuracy_unknown_facts' => (
                $this->resource['accuracy_unknown_facts']
            ),
            'accuracy_available' => (
                $this->resource['accuracy_available']
            ),
            'unknown_facts' => $this->resource['unknown_facts'],
            'complete' => $this->resource['complete'],
        ];
    }
}
