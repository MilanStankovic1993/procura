<?php

namespace App\SellPriceIntelligence;

use App\Enums\Sell\SellPriceBandStatus;
use App\Models\OwnedProduct;
use App\Models\OwnedProductAssessment;
use App\Models\SellComparableSelection;
use App\Models\SellPriceBand;
use Illuminate\Database\Eloquent\Collection;

final class CurrentSellPriceBandResolver
{
    public function matches(
        SellPriceBand $band,
        OwnedProductAssessment $assessment,
    ): bool {
        return $this->matchesVersion($band, $assessment)
            && $band->status !== SellPriceBandStatus::NeedsInput
            && $this->hasCompleteBands($band);
    }

    public function matchesVersion(
        SellPriceBand $band,
        OwnedProductAssessment $assessment,
    ): bool {
        $selection = $band->selection;

        return
            $band->owned_product_id === $assessment->owned_product_id
            && $band->owned_product_assessment_id === $assessment->getKey()
            && $selection !== null
            && $selection->owned_product_assessment_id === $assessment->getKey()
            && $selection->target_country_code === $band->target_country_code
            && $selection->target_currency_code === $band->target_currency_code
            && hash_equals(
                $band->algorithm_version,
                (string) config(
                    'sell_price_intelligence.algorithm_version',
                ),
            )
            && hash_equals(
                $selection->selector_version,
                (string) config(
                    'sell_price_intelligence.selector_version',
                ),
            );
    }

    public function resolve(
        OwnedProduct $ownedProduct,
        OwnedProductAssessment $assessment,
        string $priceBandId,
        bool $lockForUpdate = false,
    ): ?SellPriceBand {
        $query = SellPriceBand::query()
            ->forOrganization($ownedProduct->organization_id)
            ->where('owned_product_id', $ownedProduct->getKey())
            ->whereKey($priceBandId);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $band = $query->first();

        if ($band === null) {
            return null;
        }

        $selectionQuery = SellComparableSelection::query()
            ->whereKey($band->sell_comparable_selection_id);

        if ($lockForUpdate) {
            $selectionQuery->lockForUpdate();
        }

        $band->setRelation('selection', $selectionQuery->first());

        return $this->matches($band, $assessment) ? $band : null;
    }

    /**
     * @return Collection<int, SellPriceBand>
     */
    public function currentFor(
        OwnedProduct $ownedProduct,
        OwnedProductAssessment $assessment,
        bool $completeOnly = true,
    ): Collection {
        $latestRuns = SellPriceBand::query()
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
            ]);

        return SellPriceBand::query()
            ->forOrganization($ownedProduct->organization_id)
            ->where('owned_product_id', $ownedProduct->getKey())
            ->where(
                'owned_product_assessment_id',
                $assessment->getKey(),
            )
            ->whereIn('run_number', $latestRuns)
            ->with(['selection.items', 'items'])
            ->orderByDesc('run_number')
            ->get()
            ->filter(fn (SellPriceBand $band): bool => (
                $completeOnly
                    ? $this->matches($band, $assessment)
                    : $this->matchesVersion($band, $assessment)
            ))
            ->values();
    }

    private function hasCompleteBands(SellPriceBand $band): bool
    {
        return collect([
            $band->quick_sale_low_minor,
            $band->quick_sale_high_minor,
            $band->recommended_low_minor,
            $band->recommended_high_minor,
            $band->ambitious_low_minor,
            $band->ambitious_high_minor,
        ])->every(static fn (?int $value): bool => $value !== null);
    }
}
