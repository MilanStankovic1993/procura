<?php

namespace App\Actions\OwnedProducts;

use App\Models\OwnedProduct;
use App\Models\SellListingDraft;
use App\OwnedProductAssessment\CurrentOwnedProductAssessmentResolver;
use App\SellListingContent\CurrentSellListingDraftResolver;
use App\SellPriceIntelligence\CurrentSellPriceBandResolver;

final class SellListingDraftProjection
{
    public function __construct(
        private readonly CurrentOwnedProductAssessmentResolver $currentAssessment,
        private readonly CurrentSellPriceBandResolver $currentPriceBands,
        private readonly CurrentSellListingDraftResolver $currentDrafts,
    ) {}

    /** @return array<string, mixed> */
    public function for(OwnedProduct $ownedProduct): array
    {
        $limit = (int) config('sell_listing_content.history_limit');
        $assessment = $this->currentAssessment->resolve($ownedProduct);
        $history = SellListingDraft::query()
            ->forOrganization($ownedProduct->organization_id)
            ->where('owned_product_id', $ownedProduct->getKey())
            ->with([
                'facts',
                'photoChecklist',
                'generatedBy',
                'priceBand.selection.items',
            ])
            ->orderByDesc('run_number')
            ->limit($limit)
            ->get();

        return [
            'assessment_current' => $assessment !== null,
            'current_assessment_id' => $assessment?->getKey(),
            'available_price_bands' => $assessment === null
                ? collect()
                : $this->currentPriceBands->currentFor(
                    $ownedProduct,
                    $assessment,
                ),
            'drafts' => $history,
            'current_drafts' => $assessment === null
                ? collect()
                : $this->currentDrafts->currentFor(
                    $ownedProduct,
                    $assessment,
                ),
        ];
    }
}
