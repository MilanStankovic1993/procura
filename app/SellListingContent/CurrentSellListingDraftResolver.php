<?php

namespace App\SellListingContent;

use App\Models\OwnedProduct;
use App\Models\OwnedProductAssessment;
use App\Models\SellListingDraft;
use App\SellPriceIntelligence\CurrentSellPriceBandResolver;
use Illuminate\Database\Eloquent\Collection;

final class CurrentSellListingDraftResolver
{
    public function __construct(
        private readonly CurrentSellPriceBandResolver $currentPriceBand,
        private readonly SellListingVersionEvidence $versionEvidence,
    ) {}

    public function matches(
        SellListingDraft $draft,
        OwnedProductAssessment $assessment,
    ): bool {
        $templateVersion = config(
            "sell_listing_content.template_versions.{$draft->listing_language}",
        );

        return
            $draft->owned_product_assessment_id === $assessment->getKey()
            && hash_equals(
                $draft->assessment_input_hash,
                $assessment->input_hash,
            )
            && hash_equals(
                $draft->image_evidence_hash,
                $assessment->image_evidence_hash,
            )
            && $draft->priceBand !== null
            && $this->currentPriceBand->matches(
                $draft->priceBand,
                $assessment,
            )
            && hash_equals(
                $draft->price_band_input_hash,
                $draft->priceBand->input_hash,
            )
            && hash_equals(
                $draft->generator_version,
                (string) config('sell_listing_content.generator_version'),
            )
            && hash_equals(
                $draft->photo_evaluator_version,
                (string) config(
                    'sell_listing_content.photo_evaluator_version',
                ),
            )
            && is_string($templateVersion)
            && hash_equals($draft->template_version, $templateVersion)
            && hash_equals(
                (string) data_get(
                    $draft->input_snapshot,
                    'template_content_hash',
                    '',
                ),
                $this->versionEvidence->templateHash(
                    $draft->listing_language,
                ),
            )
            && hash_equals(
                (string) data_get(
                    $draft->input_snapshot,
                    'photo_policy_hash',
                    '',
                ),
                $this->versionEvidence->photoPolicyHash(),
            );
    }

    /**
     * @return Collection<int, SellListingDraft>
     */
    public function currentFor(
        OwnedProduct $ownedProduct,
        OwnedProductAssessment $assessment,
    ): Collection {
        $latestRuns = SellListingDraft::query()
            ->selectRaw('MAX(run_number)')
            ->where('organization_id', $ownedProduct->organization_id)
            ->where('owned_product_id', $ownedProduct->getKey())
            ->where(
                'owned_product_assessment_id',
                $assessment->getKey(),
            )
            ->groupBy([
                'target_country_code',
                'target_currency_code',
                'listing_language',
            ]);

        return SellListingDraft::query()
            ->forOrganization($ownedProduct->organization_id)
            ->where('owned_product_id', $ownedProduct->getKey())
            ->where(
                'owned_product_assessment_id',
                $assessment->getKey(),
            )
            ->whereIn('run_number', $latestRuns)
            ->with([
                'facts',
                'photoChecklist',
                'generatedBy',
                'priceBand.selection.items',
            ])
            ->orderByDesc('run_number')
            ->get()
            ->filter(fn (SellListingDraft $draft): bool => (
                $this->matches($draft, $assessment)
            ))
            ->values();
    }
}
