<?php

namespace App\SellPriceIntelligence\Estimators;

use App\Enums\Comparables\ComparableDecision;
use App\Enums\Pricing\PriceConfidenceLevel;
use App\Enums\Sell\SellPriceBandItemDecision;
use App\Enums\Sell\SellPriceBandStatus;
use App\Models\OwnedProductAssessment;
use App\Models\SellComparableSelection;
use App\Models\SellComparableSelectionItem;
use App\SellPriceIntelligence\Data\SellPriceBandData;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use LogicException;

final class DeterministicSellPriceBandEstimator
{
    public function estimate(
        OwnedProductAssessment $assessment,
        SellComparableSelection $selection,
    ): SellPriceBandData {
        if (
            $selection->owned_product_assessment_id !== $assessment->getKey()
            || $selection->owned_product_id !== $assessment->owned_product_id
            || $selection->organization_id !== $assessment->organization_id
        ) {
            throw new LogicException(
                'Sell price bands require a selection from the same assessment.',
            );
        }

        $selection->loadMissing('items');
        $selectionItems = $selection->items
            ->where('decision', ComparableDecision::Included)
            ->values();
        $items = $selectionItems
            ->map(
                fn (
                    SellComparableSelectionItem $item,
                    int $index,
                ): array => $this->priceBandItem(
                    $item,
                    $index,
                    $selection,
                ),
            )
            ->all();
        $calculatedAt = CarbonImmutable::now()->utc();
        $minimum = (int) config('sell_price_intelligence.minimum_selected');

        if (count($items) < $minimum) {
            return $this->needsInput(
                $assessment,
                $selection,
                $calculatedAt,
                $items,
                ['insufficient_sell_comparables'],
            );
        }

        $values = array_column($items, 'asking_price_minor');
        $preliminaryMedian = $this->median($values);
        $preliminaryMad = $this->median(array_map(
            static fn (int $value): int => abs($value - $preliminaryMedian),
            $values,
        ));

        if (
            count($values)
                >= (int) config(
                    'sell_price_intelligence.outlier_minimum_values',
                )
            && $preliminaryMad > 0
        ) {
            $threshold = $preliminaryMad
                * (int) config('sell_price_intelligence.mad_multiplier');

            foreach ($items as &$item) {
                if (
                    abs($item['asking_price_minor'] - $preliminaryMedian)
                    > $threshold
                ) {
                    $item['decision'] = SellPriceBandItemDecision::Outlier->value;
                    $item['reason_codes'][] = 'median_absolute_deviation_outlier';
                }
            }
            unset($item);
        }

        $includedItems = array_values(array_filter(
            $items,
            static fn (array $item): bool => $item['decision']
                === SellPriceBandItemDecision::Included->value,
        ));
        $outlierCount = count($items) - count($includedItems);

        if (count($includedItems) < $minimum) {
            return $this->needsInput(
                $assessment,
                $selection,
                $calculatedAt,
                $items,
                ['insufficient_sell_comparables_after_outlier_removal'],
            );
        }

        $includedValues = array_column($includedItems, 'asking_price_minor');
        [$q1, $q3] = $this->quartiles($includedValues);
        $median = $this->median($includedValues);
        $mad = $this->median(array_map(
            static fn (int $value): int => abs($value - $median),
            $includedValues,
        ));
        $weightedMedian = $this->weightedMedian($includedItems);
        $anchor = min($q3, max($q1, $weightedMedian));
        $dispersionBasisPoints = intdiv(
            max(0, $q3 - $q1) * 10000,
            max(1, $weightedMedian),
        );
        $confidenceComponents = $this->confidenceComponents(
            $assessment,
            $includedItems,
            $dispersionBasisPoints,
        );
        $confidenceBasisPoints = min(
            10000,
            max(0, array_sum($confidenceComponents)),
        );
        $confidenceLevel = match (true) {
            $confidenceBasisPoints < 5000 => PriceConfidenceLevel::Low,
            $confidenceBasisPoints < 7500 => PriceConfidenceLevel::Medium,
            default => PriceConfidenceLevel::High,
        };
        $lowConfidence = $dispersionBasisPoints
            >= (int) config(
                'sell_price_intelligence.high_dispersion_basis_points',
            )
            || $confidenceBasisPoints
                < (int) config(
                    'sell_price_intelligence.low_confidence_basis_points',
                );
        $status = $lowConfidence
            ? SellPriceBandStatus::LowConfidence
            : SellPriceBandStatus::Ready;
        $reasonCodes = [
            'asking_price_evidence_only',
            'minimum_sell_evidence_met',
            'weighted_median_recorded',
            'interquartile_range_recorded',
            'data_derived_sell_bands',
            'explicit_market_normalization_only',
        ];

        if ($outlierCount > 0) {
            $reasonCodes[] = 'extreme_outliers_excluded';
        }

        if (
            $dispersionBasisPoints
            >= (int) config(
                'sell_price_intelligence.high_dispersion_basis_points',
            )
        ) {
            $reasonCodes[] = 'high_price_dispersion';
        }

        if ($confidenceLevel === PriceConfidenceLevel::Low) {
            $reasonCodes[] = 'sell_price_confidence_low';
        }

        $unknownFacts = [
            'realized_transaction_prices',
            'expected_sale_duration',
        ];
        $verificationActions = ['confirm_realized_sale_outcomes'];

        if ($this->sourceDiversity($includedItems) < 2) {
            $unknownFacts[] = 'independent_source_diversity';
            $verificationActions[] = 'add_independent_marketplace_source';
        }

        [$inputHash, $inputSnapshot] = $this->inputEvidence(
            $assessment,
            $selection,
            $items,
            [
                'median_minor' => $median,
                'weighted_median_minor' => $weightedMedian,
                'q1_minor' => $q1,
                'q3_minor' => $q3,
                'mad_minor' => $mad,
                'dispersion_basis_points' => $dispersionBasisPoints,
            ],
        );

        return new SellPriceBandData(
            status: $status,
            algorithmVersion: (string) config(
                'sell_price_intelligence.algorithm_version',
            ),
            inputHash: $inputHash,
            calculatedAt: $calculatedAt,
            targetCountryCode: $selection->target_country_code,
            targetCurrencyCode: $selection->target_currency_code,
            inputCount: count($items),
            includedCount: count($includedItems),
            outlierCount: $outlierCount,
            quickSaleLowMinor: $q1,
            quickSaleHighMinor: $anchor,
            recommendedLowMinor: $q1,
            recommendedHighMinor: $q3,
            ambitiousLowMinor: $anchor,
            ambitiousHighMinor: $q3,
            medianMinor: $median,
            weightedMedianMinor: $weightedMedian,
            q1Minor: $q1,
            q3Minor: $q3,
            madMinor: $mad,
            dispersionBasisPoints: $dispersionBasisPoints,
            confidenceBasisPoints: $confidenceBasisPoints,
            confidenceLevel: $confidenceLevel,
            completenessBasisPoints: $assessment->completeness_basis_points,
            confidenceComponents: $confidenceComponents,
            reasonCodes: array_values(array_unique($reasonCodes)),
            unknownFacts: array_values(array_unique($unknownFacts)),
            verificationActions: array_values(
                array_unique($verificationActions),
            ),
            inputSnapshot: $inputSnapshot,
            items: $items,
        );
    }

    /** @return array<string, mixed> */
    private function priceBandItem(
        SellComparableSelectionItem $item,
        int $index,
        SellComparableSelection $selection,
    ): array {
        $normalization = $item->evidence_snapshot['market_normalization']
            ?? null;

        if (
            $normalization === null
            && (
                ($item->evidence_snapshot['country_code'] ?? null)
                    !== $selection->target_country_code
                || ($item->evidence_snapshot['currency_code'] ?? null)
                    !== $selection->target_currency_code
            )
        ) {
            throw new LogicException(
                'A cross-market Sell price item requires normalization evidence.',
            );
        }

        if (
            $normalization !== null
            && (
                ! is_array($normalization)
                || ($normalization['compatibility_status'] ?? null)
                    !== 'compatible'
                || ! is_int(
                    $normalization['normalized_amount_minor'] ?? null,
                )
                || ($normalization['target_country_code'] ?? null)
                    !== $selection->target_country_code
                || ($normalization['target_currency_code'] ?? null)
                    !== $selection->target_currency_code
            )
        ) {
            throw new LogicException(
                'Sell market-normalization evidence is invalid for the target scope.',
            );
        }

        $normalizedAmount = is_array($normalization)
            ? ($normalization['normalized_amount_minor'] ?? null)
            : null;

        return [
            'sell_comparable_selection_item_id' => $item->getKey(),
            'sell_comparable_record_id' => $item->sell_comparable_record_id,
            'position' => $index + 1,
            'decision' => SellPriceBandItemDecision::Included->value,
            'asking_price_minor' => (int) (
                $normalizedAmount
                    ?? $item->evidence_snapshot['asking_price_minor']
                    ?? 0
            ),
            'currency_code' => $normalization === null
                ? (string) (
                    $item->evidence_snapshot['currency_code']
                        ?? $selection->target_currency_code
                )
                : $selection->target_currency_code,
            'weight_basis_points' => min(
                10000,
                max(1, $item->score_basis_points),
            ),
            'reason_codes' => [
                'selected_comparable',
                $normalization === null
                    ? 'native_market_amount_used'
                    : 'explicit_market_normalization_applied',
            ],
            'evidence_snapshot' => [
                'selector_factor_scores' => $item->factor_scores,
                'selector_reason_codes' => $item->reason_codes,
                'comparable_evidence' => $item->evidence_snapshot,
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  list<string>  $reasonCodes
     */
    private function needsInput(
        OwnedProductAssessment $assessment,
        SellComparableSelection $selection,
        CarbonImmutable $calculatedAt,
        array $items,
        array $reasonCodes,
    ): SellPriceBandData {
        $includedCount = count(array_filter(
            $items,
            static fn (array $item): bool => $item['decision']
                === SellPriceBandItemDecision::Included->value,
        ));
        $outlierCount = count($items) - $includedCount;
        [$inputHash, $inputSnapshot] = $this->inputEvidence(
            $assessment,
            $selection,
            $items,
            [],
        );

        return new SellPriceBandData(
            status: SellPriceBandStatus::NeedsInput,
            algorithmVersion: (string) config(
                'sell_price_intelligence.algorithm_version',
            ),
            inputHash: $inputHash,
            calculatedAt: $calculatedAt,
            targetCountryCode: $selection->target_country_code,
            targetCurrencyCode: $selection->target_currency_code,
            inputCount: count($items),
            includedCount: $includedCount,
            outlierCount: $outlierCount,
            quickSaleLowMinor: null,
            quickSaleHighMinor: null,
            recommendedLowMinor: null,
            recommendedHighMinor: null,
            ambitiousLowMinor: null,
            ambitiousHighMinor: null,
            medianMinor: null,
            weightedMedianMinor: null,
            q1Minor: null,
            q3Minor: null,
            madMinor: null,
            dispersionBasisPoints: null,
            confidenceBasisPoints: null,
            confidenceLevel: null,
            completenessBasisPoints: $assessment->completeness_basis_points,
            confidenceComponents: [],
            reasonCodes: array_values(array_unique([
                ...$reasonCodes,
                'asking_price_evidence_only',
                'explicit_market_normalization_only',
            ])),
            unknownFacts: [
                'sufficient_eligible_sell_comparables',
                'realized_transaction_prices',
                'expected_sale_duration',
            ],
            verificationActions: [
                'add_eligible_sell_comparables',
                'confirm_realized_sale_outcomes',
            ],
            inputSnapshot: $inputSnapshot,
            items: $items,
        );
    }

    /** @param list<int> $values */
    private function median(array $values): int
    {
        sort($values, SORT_NUMERIC);
        $count = count($values);

        if ($count === 0) {
            throw new LogicException('Median requires at least one value.');
        }

        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return $values[$middle];
        }

        return BigInteger::of($values[$middle - 1])
            ->plus($values[$middle])
            ->dividedBy(2, RoundingMode::HalfEven)
            ->toInt();
    }

    /** @param list<int> $values @return array{int, int} */
    private function quartiles(array $values): array
    {
        sort($values, SORT_NUMERIC);
        $count = count($values);
        $middle = intdiv($count, 2);
        $lower = array_slice($values, 0, $middle);
        $upper = array_slice(
            $values,
            $count % 2 === 1 ? $middle + 1 : $middle,
        );

        return [
            $this->median($lower === [] ? $values : $lower),
            $this->median($upper === [] ? $values : $upper),
        ];
    }

    /** @param list<array<string, mixed>> $items */
    private function weightedMedian(array $items): int
    {
        usort($items, static fn (array $left, array $right): int => [
            $left['asking_price_minor'],
            $left['sell_comparable_record_id'],
        ] <=> [
            $right['asking_price_minor'],
            $right['sell_comparable_record_id'],
        ]);
        $threshold = intdiv(
            array_sum(array_column($items, 'weight_basis_points')) + 1,
            2,
        );
        $cumulative = 0;

        foreach ($items as $item) {
            $cumulative += $item['weight_basis_points'];

            if ($cumulative >= $threshold) {
                return $item['asking_price_minor'];
            }
        }

        throw new LogicException('Weighted median requires input evidence.');
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, int>
     */
    private function confidenceComponents(
        OwnedProductAssessment $assessment,
        array $items,
        int $dispersionBasisPoints,
    ): array {
        $count = count($items);
        $conditionKnown = $assessment->condition->value !== 'unknown';
        $consistencyScore = match (true) {
            $dispersionBasisPoints <= 1000 => 1500,
            $dispersionBasisPoints >= 5000 => 0,
            default => intdiv(
                (5000 - $dispersionBasisPoints) * 1500,
                4000,
            ),
        };

        return [
            'comparable_count' => min(2500, intdiv($count * 2500, 5)),
            'product_match_quality' => intdiv(
                $assessment->confidence_basis_points * 2500,
                10000,
            ),
            'condition_completeness' => $conditionKnown ? 1500 : 0,
            'price_consistency' => $consistencyScore,
            'source_diversity' => min(
                1000,
                intdiv(
                    $this->sourceDiversity($items) * 1000,
                    min(3, max(1, $count)),
                ),
            ),
            'freshness' => min(
                1000,
                intdiv(
                    array_sum(array_map(
                        static fn (array $item): int => (int) (
                            $item['evidence_snapshot']['selector_factor_scores']['time_relevance']
                                ?? 0
                        ),
                        $items,
                    )),
                    max(1, $count),
                ),
            ),
        ];
    }

    /** @param list<array<string, mixed>> $items */
    private function sourceDiversity(array $items): int
    {
        return count(array_unique(array_map(
            static fn (array $item): string => mb_strtolower((string) (
                $item['evidence_snapshot']['comparable_evidence']['marketplace_name']
                    ?? ''
            )),
            $items,
        )));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, int>  $statistics
     * @return array{string, array<string, mixed>}
     */
    private function inputEvidence(
        OwnedProductAssessment $assessment,
        SellComparableSelection $selection,
        array $items,
        array $statistics,
    ): array {
        $snapshot = [
            'owned_product_assessment_id' => $assessment->getKey(),
            'assessment_input_hash' => $assessment->input_hash,
            'sell_comparable_selection_id' => $selection->getKey(),
            'selection_input_hash' => $selection->input_hash,
            'algorithm_version' => config(
                'sell_price_intelligence.algorithm_version',
            ),
            'target_country_code' => $selection->target_country_code,
            'target_currency_code' => $selection->target_currency_code,
            'items' => array_map(static fn (array $item): array => [
                'sell_comparable_selection_item_id' => (
                    $item['sell_comparable_selection_item_id']
                ),
                'sell_comparable_record_id' => $item['sell_comparable_record_id'],
                'decision' => $item['decision'],
                'asking_price_minor' => $item['asking_price_minor'],
                'currency_code' => $item['currency_code'],
                'weight_basis_points' => $item['weight_basis_points'],
                'evidence_hash' => (
                    $item['evidence_snapshot']['comparable_evidence']['evidence_hash']
                        ?? null
                ),
                'market_normalization_evidence_hash' => (
                    $item['evidence_snapshot']['comparable_evidence']['market_normalization']['evidence_hash']
                        ?? null
                ),
            ], $items),
            'statistics' => $statistics,
        ];
        $encoded = json_encode(
            $snapshot,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return [hash('sha256', $encoded), $snapshot];
    }
}
