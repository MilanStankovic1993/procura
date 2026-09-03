<?php

namespace App\Actions\OwnedProducts;

use App\Enums\Outcomes\ActualSaleOutcomeType;
use App\Models\ActualCostSnapshot;
use App\Models\ActualPurchase;
use App\Models\ActualSale;
use App\Models\EstimateAccuracyReport;
use App\Models\OutcomeEstimateAttribution;
use App\Models\OwnedProduct;
use App\Models\RealizedProfit;
use App\Models\SalePortfolioEntry;

final class OutcomeTrackingProjection
{
    /** @return array<string, mixed> */
    public function for(OwnedProduct $product): array
    {
        $limit = (int) config('outcome_tracking.history_limit');
        $purchases = ActualPurchase::query()
            ->forOrganization($product->organization_id)
            ->where('owned_product_id', $product->getKey())
            ->with('actor:id,name')
            ->orderByDesc('sequence')
            ->limit($limit)
            ->get();
        $costSnapshots = ActualCostSnapshot::query()
            ->forOrganization($product->organization_id)
            ->where('owned_product_id', $product->getKey())
            ->with(['actor:id,name', 'items'])
            ->orderByDesc('sequence')
            ->limit($limit)
            ->get();
        $sales = ActualSale::query()
            ->forOrganization($product->organization_id)
            ->where('owned_product_id', $product->getKey())
            ->with(['actor:id,name', 'portfolioEvent'])
            ->orderByDesc('created_at')
            ->orderByDesc('sequence')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
        $profits = RealizedProfit::query()
            ->forOrganization($product->organization_id)
            ->where('owned_product_id', $product->getKey())
            ->orderByDesc('run_number')
            ->limit($limit)
            ->get();
        $attributions = OutcomeEstimateAttribution::query()
            ->forOrganization($product->organization_id)
            ->where('owned_product_id', $product->getKey())
            ->with([
                'actor:id,name',
                'analysis.listing:id,title',
            ])
            ->orderByDesc('sequence')
            ->limit((int) config('estimate_accuracy.history_limit'))
            ->get();
        $accuracyReports = EstimateAccuracyReport::query()
            ->forOrganization($product->organization_id)
            ->where('owned_product_id', $product->getKey())
            ->orderByDesc('sequence')
            ->limit((int) config('estimate_accuracy.history_limit'))
            ->get();
        $entries = SalePortfolioEntry::query()
            ->forOrganization($product->organization_id)
            ->where('owned_product_id', $product->getKey())
            ->with([
                'createdBy:id,name',
                'listingDraft',
                'currentEvent.actor:id,name',
                'events.actor:id,name',
                'currentActualSale',
            ])
            ->orderByDesc('sequence')
            ->limit($limit)
            ->get();
        $purchase = $purchases->first();
        $costs = $costSnapshots->first();
        $sold = $sales->first(
            static fn (ActualSale $sale): bool => (
                $sale->outcome_type === ActualSaleOutcomeType::Sold
            ),
        );
        $profit = $profits->first();
        $attribution = $attributions->first();
        $accuracyReport = $accuracyReports->first();
        $unknownFacts = [];
        $accuracyUnknownFacts = [];

        if ($purchase === null) {
            $unknownFacts[] = 'actual_purchase_missing';
        }

        if ($costs === null) {
            $unknownFacts[] = 'actual_cost_snapshot_missing';
        } elseif ($costs->unknown_count > 0) {
            $unknownFacts[] = 'actual_costs_incomplete';
        }

        if ($sold === null) {
            $unknownFacts[] = 'actual_sale_missing';
        }

        if (
            $purchase !== null
            && $costs !== null
            && $purchase->reporting_currency_code
                !== $costs->reporting_currency_code
        ) {
            $unknownFacts[] = 'purchase_cost_reporting_currency_mismatch';
        }

        if (
            $sold !== null
            && $costs !== null
            && $sold->reporting_currency_code
                !== $costs->reporting_currency_code
        ) {
            $unknownFacts[] = 'sale_cost_reporting_currency_mismatch';
        }

        if ($profit === null) {
            $unknownFacts[] = 'realized_profit_unavailable';
            $accuracyUnknownFacts[] = 'realized_profit_unavailable';
        } elseif ($attribution === null) {
            $accuracyUnknownFacts[] = 'estimate_attribution_missing';
        }

        if ($attribution !== null && $accuracyReport === null) {
            $accuracyUnknownFacts[] = 'estimate_accuracy_report_missing';
        }

        return [
            'purchases' => $purchases,
            'current_purchase_id' => $purchase?->getKey(),
            'cost_snapshots' => $costSnapshots,
            'current_cost_snapshot_id' => $costs?->getKey(),
            'sales' => $sales,
            'current_sold_sale_id' => $sold?->getKey(),
            'sale_portfolio_entries' => $entries,
            'realized_profits' => $profits,
            'current_realized_profit_id' => $profit?->getKey(),
            'estimate_attributions' => $attributions,
            'current_estimate_attribution_id' => $attribution?->getKey(),
            'estimate_accuracy_reports' => $accuracyReports,
            'current_estimate_accuracy_report_id' => (
                $accuracyReport?->getKey()
            ),
            'accuracy_unknown_facts' => array_values(
                array_unique($accuracyUnknownFacts),
            ),
            'accuracy_available' => $accuracyReport !== null,
            'unknown_facts' => array_values(array_unique($unknownFacts)),
            'complete' => $profit !== null,
        ];
    }
}
