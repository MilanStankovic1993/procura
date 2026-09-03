<?php

namespace App\Actions\OwnedProducts;

use App\Enums\Sell\SellPriceIntelligenceMetricStage;
use App\Models\OwnedProduct;
use App\Models\OwnedProductAssessment;
use App\Models\SellComparableSelection;
use App\Models\SellPriceBand;
use App\SellPriceIntelligence\Data\SellComparableSelectionData;
use App\SellPriceIntelligence\Data\SellPriceBandData;
use App\SellPriceIntelligence\Estimators\DeterministicSellPriceBandEstimator;
use App\SellPriceIntelligence\Metrics\SellPriceIntelligenceMetricTimer;
use App\SellPriceIntelligence\Selectors\DeterministicSellComparableSelector;
use Illuminate\Support\Facades\DB;
use LogicException;

final class RefreshSellPriceIntelligence
{
    public function __construct(
        private readonly DeterministicSellComparableSelector $selector,
        private readonly DeterministicSellPriceBandEstimator $estimator,
    ) {}

    /** @return array{selection: SellComparableSelection, price_band: SellPriceBand} */
    public function refresh(
        OwnedProduct $ownedProduct,
        OwnedProductAssessment $assessment,
        string $targetCountryCode,
        string $targetCurrencyCode,
        SellPriceIntelligenceMetricTimer $metric,
    ): array {
        if (DB::transactionLevel() < 1) {
            throw new LogicException(
                'Sell price-intelligence refresh requires an active transaction.',
            );
        }

        $selectionData = $metric->measure(
            SellPriceIntelligenceMetricStage::ComparableSelection,
            fn (): SellComparableSelectionData => $this->selector->select(
                $ownedProduct,
                $assessment,
                strtoupper($targetCountryCode),
                strtoupper($targetCurrencyCode),
            ),
        );
        $selection = $metric->measure(
            SellPriceIntelligenceMetricStage::SelectionPersistence,
            fn (): SellComparableSelection => $this->recordSelection(
                $ownedProduct,
                $assessment->getKey(),
                $selectionData,
            ),
        );
        $bandData = $metric->measure(
            SellPriceIntelligenceMetricStage::PriceBandEstimation,
            fn (): SellPriceBandData => $this->estimator->estimate(
                $assessment,
                $selection,
            ),
        );
        $priceBand = $metric->measure(
            SellPriceIntelligenceMetricStage::PriceBandPersistence,
            fn (): SellPriceBand => $this->recordPriceBand(
                $ownedProduct,
                $assessment->getKey(),
                $selection,
                $bandData,
            ),
        );
        $metric->observeScope(
            candidateCount: $selectionData->candidateCount,
            includedCount: count($selectionData->included),
            excludedCount: count($selectionData->excluded),
            bandInputCount: $bandData->inputCount,
            outlierCount: $bandData->outlierCount,
            selectionReplayed: ! $selection->wasRecentlyCreated,
            priceBandReplayed: ! $priceBand->wasRecentlyCreated,
        );

        return [
            'selection' => $selection,
            'price_band' => $priceBand,
        ];
    }

    private function recordSelection(
        OwnedProduct $ownedProduct,
        string $assessmentId,
        SellComparableSelectionData $result,
    ): SellComparableSelection {
        $selectionKey = hash('sha256', implode('|', [
            $ownedProduct->getKey(),
            $assessmentId,
            $result->selectorVersion,
            $result->inputHash,
        ]));
        $existing = SellComparableSelection::query()
            ->where('selection_key', $selectionKey)
            ->first();

        if ($existing !== null) {
            return $existing->load('items');
        }

        $runNumber = ((int) SellComparableSelection::query()
            ->where('owned_product_id', $ownedProduct->getKey())
            ->max('run_number')) + 1;
        $selection = SellComparableSelection::query()->create([
            'organization_id' => $ownedProduct->organization_id,
            'owned_product_id' => $ownedProduct->getKey(),
            'owned_product_assessment_id' => $assessmentId,
            'run_number' => $runNumber,
            'status' => $result->status,
            'selector_version' => $result->selectorVersion,
            'input_hash' => $result->inputHash,
            'selection_key' => $selectionKey,
            'target_country_code' => $result->targetCountryCode,
            'target_currency_code' => $result->targetCurrencyCode,
            'candidate_count' => $result->candidateCount,
            'included_count' => count($result->included),
            'excluded_count' => count($result->excluded),
            'minimum_required' => $result->minimumRequired,
            'reason_codes' => $result->reasonCodes,
            'input_snapshot' => $result->inputSnapshot,
        ]);
        $items = [];

        foreach ($result->included as $index => $item) {
            $items[] = [
                ...$item,
                'decision' => 'included',
                'rank' => $index + 1,
            ];
        }

        foreach ($result->excluded as $item) {
            $items[] = [
                ...$item,
                'decision' => 'excluded',
                'rank' => null,
            ];
        }

        $selection->items()->createMany($items);

        return $selection->load('items');
    }

    private function recordPriceBand(
        OwnedProduct $ownedProduct,
        string $assessmentId,
        SellComparableSelection $selection,
        SellPriceBandData $result,
    ): SellPriceBand {
        $priceBandKey = hash('sha256', implode('|', [
            $ownedProduct->getKey(),
            $assessmentId,
            $selection->getKey(),
            $result->algorithmVersion,
            $result->inputHash,
        ]));
        $existing = SellPriceBand::query()
            ->where('price_band_key', $priceBandKey)
            ->first();

        if ($existing !== null) {
            return $existing->load(['items', 'selection.items']);
        }

        $runNumber = ((int) SellPriceBand::query()
            ->where('owned_product_id', $ownedProduct->getKey())
            ->max('run_number')) + 1;
        $priceBand = SellPriceBand::query()->create([
            'organization_id' => $ownedProduct->organization_id,
            'owned_product_id' => $ownedProduct->getKey(),
            'owned_product_assessment_id' => $assessmentId,
            'sell_comparable_selection_id' => $selection->getKey(),
            'run_number' => $runNumber,
            'status' => $result->status,
            'algorithm_version' => $result->algorithmVersion,
            'input_hash' => $result->inputHash,
            'price_band_key' => $priceBandKey,
            'calculated_at' => $result->calculatedAt,
            'target_country_code' => $result->targetCountryCode,
            'target_currency_code' => $result->targetCurrencyCode,
            'input_count' => $result->inputCount,
            'included_count' => $result->includedCount,
            'outlier_count' => $result->outlierCount,
            'quick_sale_low_minor' => $result->quickSaleLowMinor,
            'quick_sale_high_minor' => $result->quickSaleHighMinor,
            'recommended_low_minor' => $result->recommendedLowMinor,
            'recommended_high_minor' => $result->recommendedHighMinor,
            'ambitious_low_minor' => $result->ambitiousLowMinor,
            'ambitious_high_minor' => $result->ambitiousHighMinor,
            'median_minor' => $result->medianMinor,
            'weighted_median_minor' => $result->weightedMedianMinor,
            'q1_minor' => $result->q1Minor,
            'q3_minor' => $result->q3Minor,
            'mad_minor' => $result->madMinor,
            'dispersion_basis_points' => $result->dispersionBasisPoints,
            'confidence_basis_points' => $result->confidenceBasisPoints,
            'confidence_level' => $result->confidenceLevel,
            'completeness_basis_points' => $result->completenessBasisPoints,
            'confidence_components' => $result->confidenceComponents,
            'reason_codes' => $result->reasonCodes,
            'unknown_facts' => $result->unknownFacts,
            'verification_actions' => $result->verificationActions,
            'input_snapshot' => $result->inputSnapshot,
        ]);
        $priceBand->items()->createMany($result->items);

        return $priceBand->load(['items', 'selection.items']);
    }
}
