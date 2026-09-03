<?php

namespace App\SalePortfolio;

use App\Enums\Sell\SellListingDraftStatus;
use App\Enums\Sell\SellPhotoReadinessStatus;
use App\Models\SalePortfolioEntry;
use App\OwnedProductAssessment\CurrentOwnedProductAssessmentResolver;
use App\SellListingContent\CurrentSellListingDraftResolver;

final class SalePortfolioSourceEvidence
{
    public function __construct(
        private readonly CurrentOwnedProductAssessmentResolver $currentAssessment,
        private readonly CurrentSellListingDraftResolver $currentDraft,
    ) {}

    public function matches(
        SalePortfolioEntry $entry,
        bool $lockForUpdate = false,
    ): bool {
        $entry->loadMissing('ownedProduct');
        $product = $entry->ownedProduct;
        $assessment = $this->currentAssessment->resolve(
            $product,
            $lockForUpdate,
        );
        $entry->loadMissing('listingDraft.priceBand');
        $draft = $entry->listingDraft;

        return
            $assessment !== null
            && $draft !== null
            && $draft->organization_id === $entry->organization_id
            && $draft->owned_product_id === $product->getKey()
            && $draft->status === SellListingDraftStatus::Ready
            && $draft->photo_readiness_status
                === SellPhotoReadinessStatus::Ready
            && hash_equals(
                $entry->listing_draft_input_hash,
                $draft->input_hash,
            )
            && hash_equals(
                $entry->assessment_input_hash,
                $draft->assessment_input_hash,
            )
            && hash_equals(
                $entry->price_band_input_hash,
                $draft->price_band_input_hash,
            )
            && hash_equals(
                $entry->image_evidence_hash,
                $draft->image_evidence_hash,
            )
            && $this->currentDraft->matches($draft, $assessment);
    }
}
