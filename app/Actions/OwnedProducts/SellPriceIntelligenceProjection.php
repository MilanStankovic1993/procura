<?php

namespace App\Actions\OwnedProducts;

use App\Models\OwnedProduct;
use App\Models\SellComparableRecord;
use App\Models\SellComparableSelection;
use App\Models\SellPriceBand;
use App\OwnedProductAssessment\CurrentOwnedProductAssessmentResolver;
use App\SellPriceIntelligence\CurrentSellPriceBandResolver;

final class SellPriceIntelligenceProjection
{
    public function __construct(
        private readonly CurrentOwnedProductAssessmentResolver $currentAssessment,
        private readonly CurrentSellPriceBandResolver $currentPriceBand,
    ) {}

    /** @return array<string, mixed> */
    public function for(OwnedProduct $ownedProduct): array
    {
        $limit = (int) config('sell_price_intelligence.history_limit');
        $normalizationLimit = (int) config(
            'sell_price_intelligence.normalization_history_limit',
        );
        $assessment = $this->currentAssessment->resolve($ownedProduct);
        $records = SellComparableRecord::query()
            ->forOrganization($ownedProduct->organization_id)
            ->where('owned_product_id', $ownedProduct->getKey())
            ->with([
                'marketplaceSource',
                'productVariant',
                'createdBy',
                'marketNormalizations' => fn ($query) => $query
                    ->with(['createdBy', 'exchangeRate'])
                    ->limit($normalizationLimit),
            ])
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
        $selections = SellComparableSelection::query()
            ->forOrganization($ownedProduct->organization_id)
            ->where('owned_product_id', $ownedProduct->getKey())
            ->with('items')
            ->orderByDesc('run_number')
            ->limit($limit)
            ->get();
        $priceBands = SellPriceBand::query()
            ->forOrganization($ownedProduct->organization_id)
            ->where('owned_product_id', $ownedProduct->getKey())
            ->with(['items', 'selection.items'])
            ->orderByDesc('run_number')
            ->limit($limit)
            ->get();
        $currentPriceBands = $assessment === null
            ? collect()
            : $this->currentPriceBand->currentFor(
                $ownedProduct,
                $assessment,
                completeOnly: false,
            );

        return [
            'assessment_current' => $assessment !== null,
            'current_assessment_id' => $assessment?->getKey(),
            'records' => $records,
            'selections' => $selections,
            'price_bands' => $priceBands,
            'current_price_bands' => $currentPriceBands,
        ];
    }
}
