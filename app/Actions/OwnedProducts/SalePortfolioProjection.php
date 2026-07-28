<?php

namespace App\Actions\OwnedProducts;

use App\Enums\Sell\SellListingDraftStatus;
use App\Enums\Sell\SellPhotoReadinessStatus;
use App\Models\OwnedProduct;
use App\Models\SalePortfolioEntry;
use App\OwnedProductAssessment\CurrentOwnedProductAssessmentResolver;
use App\SellListingContent\CurrentSellListingDraftResolver;

final class SalePortfolioProjection
{
    public function __construct(
        private readonly CurrentOwnedProductAssessmentResolver $currentAssessment,
        private readonly CurrentSellListingDraftResolver $currentDrafts,
    ) {}

    /** @return array<string, mixed> */
    public function for(OwnedProduct $product): array
    {
        $assessment = $this->currentAssessment->resolve($product);
        $currentDrafts = $assessment === null
            ? collect()
            : $this->currentDrafts->currentFor($product, $assessment);
        $readyDrafts = $currentDrafts
            ->filter(static fn ($draft): bool => (
                $draft->status === SellListingDraftStatus::Ready
                && $draft->photo_readiness_status
                    === SellPhotoReadinessStatus::Ready
            ))
            ->values();
        $historyLimit = (int) config('sale_portfolio.history_limit');
        $entries = SalePortfolioEntry::query()
            ->forOrganization($product->organization_id)
            ->where('owned_product_id', $product->getKey())
            ->with([
                'createdBy:id,name',
                'listingDraft',
                'currentEvent.actor:id,name',
                'currentActualSale',
                'events' => static fn ($query) => $query
                    ->with('actor:id,name')
                    ->limit($historyLimit),
            ])
            ->orderByDesc('sequence')
            ->limit($historyLimit)
            ->get();
        $currentDraftsById = $currentDrafts->keyBy->getKey();

        $entries->each(static function (
            SalePortfolioEntry $entry,
        ) use ($currentDraftsById): void {
            $draft = $currentDraftsById->get(
                $entry->sell_listing_draft_id,
            );
            $entry->setAttribute(
                'source_evidence_current',
                $draft !== null
                && hash_equals(
                    $entry->listing_draft_input_hash,
                    $draft->input_hash,
                ),
            );
        });
        $usedDraftIds = $entries
            ->pluck('sell_listing_draft_id')
            ->all();

        return [
            'assessment_current' => $assessment !== null,
            'available_listing_drafts' => $readyDrafts
                ->reject(static fn ($draft): bool => in_array(
                    $draft->getKey(),
                    $usedDraftIds,
                    true,
                ))
                ->values(),
            'entries' => $entries,
            'current_entry_id' => $entries->first()?->getKey(),
        ];
    }
}
