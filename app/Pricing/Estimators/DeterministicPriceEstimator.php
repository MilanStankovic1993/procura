<?php

namespace App\Pricing\Estimators;

use App\Enums\Comparables\ComparableDecision;
use App\Enums\Comparables\ComparableSetStatus;
use App\Enums\Pricing\ExchangeRateDirection;
use App\Enums\Pricing\ExchangeRateResolutionStatus;
use App\Enums\Pricing\PriceConfidenceLevel;
use App\Enums\Pricing\PriceEstimateItemDecision;
use App\Enums\Pricing\PriceEstimateStatus;
use App\Models\Analysis;
use App\Models\ComparableSet;
use App\Models\ComparableSetItem;
use App\Models\Currency;
use App\Pricing\Contracts\ExchangeRateResolver;
use App\Pricing\Contracts\PriceEstimator;
use App\Pricing\Data\PriceEstimateData;
use App\Pricing\MinorMoneyConverter;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use LogicException;
use Throwable;

class DeterministicPriceEstimator implements PriceEstimator
{
    public function __construct(
        private readonly ExchangeRateResolver $exchangeRates,
        private readonly MinorMoneyConverter $money,
    ) {}

    public function estimate(
        Analysis $analysis,
        ComparableSet $comparableSet,
        array $targetFacts = [],
    ): PriceEstimateData {
        if (
            $comparableSet->analysis_id !== $analysis->getKey()
            || $comparableSet->organization_id !== $analysis->organization_id
        ) {
            throw new LogicException(
                'The comparable set does not belong to the price-estimation analysis.',
            );
        }

        if (
            $comparableSet->status !== ComparableSetStatus::Ready
            || $comparableSet->target_currency_code === null
        ) {
            throw new LogicException(
                'Price estimation requires a ready comparable set and target currency.',
            );
        }

        $comparableSet->loadMissing(['items', 'productMatch']);
        $setItems = $comparableSet->items
            ->where('decision', ComparableDecision::Included)
            ->take((int) config('price_estimation.max_inputs') + 1)
            ->values();

        if ($setItems->count() > (int) config('price_estimation.max_inputs')) {
            throw new LogicException(
                'Price-estimation input exceeded its configured hard bound.',
            );
        }

        $calculationAt = CarbonImmutable::now()->utc();
        $targetCurrencyCode = $comparableSet->target_currency_code;
        $currencyCodes = $setItems
            ->map(static fn (ComparableSetItem $item): mixed => $item->evidence_snapshot['currency_code'] ?? null)
            ->filter(static fn (mixed $code): bool => is_string($code))
            ->push($targetCurrencyCode)
            ->unique()
            ->values();
        $currencies = Currency::query()
            ->whereIn('code', $currencyCodes)
            ->get()
            ->keyBy('code');
        $targetCurrency = $currencies->get($targetCurrencyCode);

        if ($targetCurrency === null) {
            throw new LogicException(
                'Target currency metadata is unavailable for price estimation.',
            );
        }

        $items = [];

        foreach ($setItems as $index => $setItem) {
            $evidence = $setItem->evidence_snapshot;
            $sourceCurrencyCode = is_string($evidence['currency_code'] ?? null)
                ? strtoupper($evidence['currency_code'])
                : '';
            $originalAmount = is_int($evidence['asking_price_minor'] ?? null)
                ? $evidence['asking_price_minor']
                : (int) ($evidence['asking_price_minor'] ?? -1);
            $sourceCurrency = $currencies->get($sourceCurrencyCode);
            $marketNormalization = is_array(
                $evidence['market_normalization'] ?? null,
            ) ? $evidence['market_normalization'] : null;
            $resolution = null;
            $decision = PriceEstimateItemDecision::Included;
            $targetAmount = null;
            $reasonCodes = [];
            $exchangeRateId = null;
            $rateDirection = ExchangeRateDirection::Unresolved;
            $rateValue = null;
            $rateEffectiveAt = null;
            $rateProvider = null;
            $rateProviderReference = null;

            if ($sourceCurrency === null) {
                $decision = PriceEstimateItemDecision::InvalidAmount;
                $reasonCodes[] = 'currency_metadata_missing';
            } elseif ($marketNormalization !== null) {
                $normalizedAmount = $marketNormalization['normalized_amount_minor']
                    ?? null;
                $normalizationDirection = is_string(
                    $marketNormalization['rate_direction'] ?? null,
                )
                    ? ExchangeRateDirection::tryFrom(
                        $marketNormalization['rate_direction'],
                    )
                    : null;
                $validNormalization = (
                    ($marketNormalization['compatibility_status'] ?? null)
                        === 'compatible'
                    && ($marketNormalization['source_country_code'] ?? null)
                        === ($evidence['country_code'] ?? null)
                    && ($marketNormalization['target_country_code'] ?? null)
                        === $comparableSet->target_country_code
                    && ($marketNormalization['source_currency_code'] ?? null)
                        === $sourceCurrencyCode
                    && ($marketNormalization['target_currency_code'] ?? null)
                        === $targetCurrencyCode
                    && ($marketNormalization['source_amount_minor'] ?? null)
                        === $originalAmount
                    && is_int($normalizedAmount)
                    && $normalizedAmount >= 0
                    && $normalizationDirection !== null
                );

                if (! $validNormalization) {
                    $decision = PriceEstimateItemDecision::InvalidNormalization;
                    $reasonCodes[] = 'market_normalization_evidence_invalid';
                } else {
                    $targetAmount = $normalizedAmount;
                    $exchangeRateId = is_string(
                        $marketNormalization['exchange_rate_id'] ?? null,
                    )
                        ? $marketNormalization['exchange_rate_id']
                        : null;
                    $rateDirection = $normalizationDirection;
                    $rateValue = is_string(
                        $marketNormalization['rate_value'] ?? null,
                    )
                        ? $marketNormalization['rate_value']
                        : null;
                    $rateEffectiveAt = is_string(
                        $marketNormalization['rate_effective_at'] ?? null,
                    )
                        ? CarbonImmutable::parse(
                            $marketNormalization['rate_effective_at'],
                        )->utc()
                        : null;
                    $rateProvider = is_string(
                        $marketNormalization['rate_provider'] ?? null,
                    )
                        ? $marketNormalization['rate_provider']
                        : null;
                    $rateProviderReference = is_string(
                        $marketNormalization['rate_provider_reference'] ?? null,
                    )
                        ? $marketNormalization['rate_provider_reference']
                        : null;
                    $reasonCodes = [
                        ...$this->stringList(
                            $marketNormalization['reason_codes'] ?? [],
                        ),
                        'cross_market_normalization_applied',
                    ];
                }
            } else {
                $resolution = $this->exchangeRates->resolve(
                    $sourceCurrencyCode,
                    $targetCurrencyCode,
                    $calculationAt,
                );

                if ($resolution->status === ExchangeRateResolutionStatus::Missing) {
                    $decision = PriceEstimateItemDecision::MissingRate;
                    $reasonCodes[] = $resolution->reasonCode;
                } elseif ($resolution->status === ExchangeRateResolutionStatus::Stale) {
                    $decision = PriceEstimateItemDecision::StaleRate;
                    $reasonCodes[] = $resolution->reasonCode;
                } else {
                    try {
                        $targetAmount = $this->money->convert(
                            $originalAmount,
                            $sourceCurrency->minor_unit,
                            $targetCurrency->minor_unit,
                            $resolution->rateValue ?? '',
                        );
                        $reasonCodes[] = $resolution->reasonCode;
                    } catch (Throwable) {
                        $decision = PriceEstimateItemDecision::InvalidAmount;
                        $reasonCodes[] = 'converted_amount_invalid';
                    }
                }

                if ($resolution !== null) {
                    $exchangeRateId = $resolution->exchangeRateId;
                    $rateDirection = $resolution->direction;
                    $rateValue = $resolution->rateValue;
                    $rateEffectiveAt = $resolution->effectiveAt;
                    $rateProvider = $resolution->provider;
                    $rateProviderReference = $resolution->providerReference;
                }
            }

            if (
                $targetAmount !== null
                && (
                    $targetAmount < 0
                    || $targetAmount > MinorMoneyConverter::MAX_SAFE_MINOR
                )
            ) {
                $decision = PriceEstimateItemDecision::InvalidAmount;
                $targetAmount = null;
                $reasonCodes[] = 'normalized_amount_invalid';
            }

            $items[] = [
                'comparable_set_item_id' => $setItem->getKey(),
                'comparable_record_id' => $setItem->comparable_record_id,
                'exchange_rate_id' => $exchangeRateId,
                'position' => $index + 1,
                'decision' => $decision->value,
                'original_amount_minor' => max(0, $originalAmount),
                'original_currency_code' => $sourceCurrencyCode ?: $targetCurrencyCode,
                'target_amount_minor' => $targetAmount,
                'target_currency_code' => $targetCurrencyCode,
                'weight_basis_points' => min(
                    10000,
                    max(1, $setItem->score_basis_points),
                ),
                'rate_direction' => $rateDirection->value,
                'rate_value' => $rateValue,
                'rate_effective_at' => $rateEffectiveAt,
                'rate_provider' => $rateProvider,
                'rate_provider_reference' => $rateProviderReference,
                'reason_codes' => array_values(array_unique($reasonCodes)),
                'evidence_snapshot' => [
                    'comparable_evidence' => $evidence,
                    'selector_rank' => $setItem->rank,
                    'selector_score_basis_points' => $setItem->score_basis_points,
                    'selector_factor_scores' => $setItem->factor_scores,
                    'selector_reason_codes' => $setItem->reason_codes,
                ],
            ];
        }

        $minimumValues = max(
            $comparableSet->minimum_required,
            (int) config('price_estimation.minimum_values'),
        );
        $unresolvedCount = count(array_filter(
            $items,
            static fn (array $item): bool => $item['decision']
                !== PriceEstimateItemDecision::Included->value,
        ));

        if ($unresolvedCount > 0) {
            return $this->needsInput(
                $analysis,
                $comparableSet,
                $calculationAt,
                $targetFacts,
                $items,
                [
                    'price_conversion_evidence_incomplete',
                    ...collect($items)->flatMap(
                        static fn (array $item): array => $item['reason_codes'],
                    )->all(),
                ],
            );
        }

        $preliminaryValues = array_map(
            static fn (array $item): int => $item['target_amount_minor'],
            $items,
        );

        if (count($preliminaryValues) < $minimumValues) {
            return $this->needsInput(
                $analysis,
                $comparableSet,
                $calculationAt,
                $targetFacts,
                $items,
                ['insufficient_price_evidence'],
            );
        }

        $preliminaryMedian = $this->median($preliminaryValues);
        $preliminaryMad = $this->median(array_map(
            static fn (int $value): int => abs($value - $preliminaryMedian),
            $preliminaryValues,
        ));
        $outlierCount = 0;

        if (
            count($preliminaryValues)
                >= (int) config('price_estimation.outlier_minimum_values')
            && $preliminaryMad > 0
        ) {
            $outlierThreshold = $preliminaryMad
                * (int) config('price_estimation.mad_multiplier');

            foreach ($items as &$item) {
                if (
                    abs($item['target_amount_minor'] - $preliminaryMedian)
                    > $outlierThreshold
                ) {
                    $item['decision'] = PriceEstimateItemDecision::Outlier->value;
                    $item['reason_codes'][] = 'median_absolute_deviation_outlier';
                    $outlierCount++;
                }
            }
            unset($item);
        }

        $includedItems = array_values(array_filter(
            $items,
            static fn (array $item): bool => $item['decision']
                === PriceEstimateItemDecision::Included->value,
        ));

        if (count($includedItems) < $minimumValues) {
            return $this->needsInput(
                $analysis,
                $comparableSet,
                $calculationAt,
                $targetFacts,
                $items,
                ['insufficient_price_evidence_after_outlier_removal'],
                preliminaryStatistics: [
                    'median_minor' => $preliminaryMedian,
                    'mad_minor' => $preliminaryMad,
                ],
            );
        }

        $values = array_map(
            static fn (array $item): int => $item['target_amount_minor'],
            $includedItems,
        );
        [$q1, $q3] = $this->quartiles($values);
        $median = $this->median($values);
        $mad = $this->median(array_map(
            static fn (int $value): int => abs($value - $median),
            $values,
        ));
        $weightedMedian = $this->weightedMedian($includedItems);
        $dispersionBasisPoints = intdiv(
            max(0, $q3 - $q1) * 10000,
            max(1, $weightedMedian),
        );
        $confidenceComponents = $this->confidenceComponents(
            $comparableSet,
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
            >= (int) config('price_estimation.high_dispersion_basis_points')
            || $confidenceBasisPoints
                < (int) config('price_estimation.low_confidence_basis_points');
        $status = $lowConfidence
            ? PriceEstimateStatus::LowConfidence
            : PriceEstimateStatus::Estimated;
        $reasonCodes = [
            'minimum_price_evidence_met',
            'weighted_median_estimate',
            'interquartile_range_recorded',
            'median_absolute_deviation_recorded',
            collect($includedItems)->every(
                static fn (array $item): bool => $item['rate_direction']
                    === ExchangeRateDirection::Identity->value,
            )
                ? 'identity_currency_conversion_only'
                : 'dated_exchange_rates_applied',
        ];

        if (collect($includedItems)->contains(
            static fn (array $item): bool => is_array(
                $item['evidence_snapshot']['comparable_evidence']['market_normalization']
                    ?? null,
            ),
        )) {
            $reasonCodes[] = 'cross_market_normalization_applied';
        }

        if ($outlierCount > 0) {
            $reasonCodes[] = 'extreme_outliers_excluded';
        }

        if (
            $dispersionBasisPoints
            >= (int) config('price_estimation.high_dispersion_basis_points')
        ) {
            $reasonCodes[] = 'high_price_dispersion';
        }

        if ($confidenceLevel === PriceConfidenceLevel::Low) {
            $reasonCodes[] = 'price_confidence_low';
        }

        $statistics = [
            'preliminary_median_minor' => $preliminaryMedian,
            'preliminary_mad_minor' => $preliminaryMad,
            'median_minor' => $median,
            'weighted_median_minor' => $weightedMedian,
            'q1_minor' => $q1,
            'q3_minor' => $q3,
            'mad_minor' => $mad,
            'dispersion_basis_points' => $dispersionBasisPoints,
        ];
        [$inputHash, $inputSnapshot] = $this->inputEvidence(
            $analysis,
            $comparableSet,
            $calculationAt,
            $targetFacts,
            $items,
            $statistics,
        );

        return new PriceEstimateData(
            status: $status,
            algorithmVersion: (string) config('price_estimation.algorithm_version'),
            rateResolverVersion: (string) config(
                'price_estimation.rate_resolver_version',
            ),
            inputHash: $inputHash,
            calculationAt: $calculationAt,
            targetCountryCode: $comparableSet->target_country_code,
            targetCurrencyCode: $targetCurrencyCode,
            inputCount: count($items),
            includedCount: count($includedItems),
            outlierCount: $outlierCount,
            unresolvedCount: 0,
            estimateLowMinor: $q1,
            estimateMinor: $weightedMedian,
            estimateHighMinor: $q3,
            medianMinor: $median,
            weightedMedianMinor: $weightedMedian,
            q1Minor: $q1,
            q3Minor: $q3,
            madMinor: $mad,
            dispersionBasisPoints: $dispersionBasisPoints,
            confidenceBasisPoints: $confidenceBasisPoints,
            confidenceLevel: $confidenceLevel,
            reasonCodes: array_values(array_unique($reasonCodes)),
            confidenceComponents: $confidenceComponents,
            inputSnapshot: $inputSnapshot,
            items: $items,
        );
    }

    /**
     * @param  array<string, mixed>  $targetFacts
     * @param  list<array<string, mixed>>  $items
     * @param  list<string>  $reasonCodes
     * @param  array<string, int>  $preliminaryStatistics
     */
    private function needsInput(
        Analysis $analysis,
        ComparableSet $comparableSet,
        CarbonImmutable $calculationAt,
        array $targetFacts,
        array $items,
        array $reasonCodes,
        array $preliminaryStatistics = [],
    ): PriceEstimateData {
        $includedCount = count(array_filter(
            $items,
            static fn (array $item): bool => $item['decision']
                === PriceEstimateItemDecision::Included->value,
        ));
        $outlierCount = count(array_filter(
            $items,
            static fn (array $item): bool => $item['decision']
                === PriceEstimateItemDecision::Outlier->value,
        ));
        $unresolvedCount = count($items) - $includedCount - $outlierCount;
        [$inputHash, $inputSnapshot] = $this->inputEvidence(
            $analysis,
            $comparableSet,
            $calculationAt,
            $targetFacts,
            $items,
            $preliminaryStatistics,
        );

        return new PriceEstimateData(
            status: PriceEstimateStatus::NeedsInput,
            algorithmVersion: (string) config('price_estimation.algorithm_version'),
            rateResolverVersion: (string) config(
                'price_estimation.rate_resolver_version',
            ),
            inputHash: $inputHash,
            calculationAt: $calculationAt,
            targetCountryCode: $comparableSet->target_country_code,
            targetCurrencyCode: (string) $comparableSet->target_currency_code,
            inputCount: count($items),
            includedCount: $includedCount,
            outlierCount: $outlierCount,
            unresolvedCount: $unresolvedCount,
            estimateLowMinor: null,
            estimateMinor: null,
            estimateHighMinor: null,
            medianMinor: null,
            weightedMedianMinor: null,
            q1Minor: null,
            q3Minor: null,
            madMinor: null,
            dispersionBasisPoints: null,
            confidenceBasisPoints: null,
            confidenceLevel: null,
            reasonCodes: array_values(array_unique($reasonCodes)),
            confidenceComponents: [],
            inputSnapshot: $inputSnapshot,
            items: $items,
        );
    }

    /**
     * @param  list<int>  $values
     */
    private function median(array $values): int
    {
        sort($values, SORT_NUMERIC);
        $count = count($values);
        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return $values[$middle];
        }

        return BigInteger::of($values[$middle - 1])
            ->plus($values[$middle])
            ->dividedBy(2, RoundingMode::HalfEven)
            ->toInt();
    }

    /**
     * @param  list<int>  $values
     * @return array{int, int}
     */
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

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function weightedMedian(array $items): int
    {
        usort($items, static fn (array $left, array $right): int => [
            $left['target_amount_minor'],
            $left['comparable_record_id'],
        ] <=> [
            $right['target_amount_minor'],
            $right['comparable_record_id'],
        ]);
        $totalWeight = array_sum(array_column($items, 'weight_basis_points'));
        $threshold = intdiv($totalWeight + 1, 2);
        $cumulative = 0;

        foreach ($items as $item) {
            $cumulative += $item['weight_basis_points'];

            if ($cumulative >= $threshold) {
                return $item['target_amount_minor'];
            }
        }

        throw new LogicException('Weighted-median input unexpectedly contained no values.');
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, int>
     */
    private function confidenceComponents(
        ComparableSet $comparableSet,
        array $items,
        int $dispersionBasisPoints,
    ): array {
        $count = count($items);
        $countScore = min(2500, intdiv($count * 2500, 5));
        $matchScore = intdiv(
            $comparableSet->productMatch->confidence_basis_points * 2500,
            10000,
        );
        $conditionScore = intdiv(
            array_sum(array_map(
                static fn (array $item): int => (int) (
                    $item['evidence_snapshot']['selector_factor_scores']['condition_similarity']
                    ?? 0
                ),
                $items,
            )),
            max(1, $count),
        );
        $consistencyScore = match (true) {
            $dispersionBasisPoints <= 1000 => 1500,
            $dispersionBasisPoints >= 5000 => 0,
            default => intdiv(
                (5000 - $dispersionBasisPoints) * 1500,
                4000,
            ),
        };
        $sourceDiversity = count(array_unique(array_map(
            static fn (array $item): string => mb_strtolower((string) (
                $item['evidence_snapshot']['comparable_evidence']['marketplace_name']
                ?? ''
            )),
            $items,
        )));
        $diversityScore = min(
            1000,
            intdiv($sourceDiversity * 1000, min(3, max(1, $count))),
        );
        $freshnessScore = min(
            1000,
            intdiv(
                array_sum(array_map(
                    static fn (array $item): int => (int) (
                        $item['evidence_snapshot']['selector_factor_scores']['time_relevance']
                        ?? 0
                    ),
                    $items,
                )) * 1000,
                max(1, $count * 700),
            ),
        );

        return [
            'comparable_count' => $countScore,
            'product_match_quality' => $matchScore,
            'condition_completeness' => min(1500, max(0, $conditionScore)),
            'price_consistency' => $consistencyScore,
            'source_diversity' => $diversityScore,
            'freshness' => $freshnessScore,
        ];
    }

    /**
     * @param  array<string, mixed>  $targetFacts
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, int>  $statistics
     * @return array{string, array<string, mixed>}
     */
    private function inputEvidence(
        Analysis $analysis,
        ComparableSet $comparableSet,
        CarbonImmutable $calculationAt,
        array $targetFacts,
        array $items,
        array $statistics,
    ): array {
        $hashInput = [
            'analysis_id' => $analysis->getKey(),
            'comparable_set_id' => $comparableSet->getKey(),
            'comparable_set_input_hash' => $comparableSet->input_hash,
            'algorithm_version' => config('price_estimation.algorithm_version'),
            'rate_resolver_version' => config(
                'price_estimation.rate_resolver_version',
            ),
            'target_country_code' => $comparableSet->target_country_code,
            'target_currency_code' => $comparableSet->target_currency_code,
            'target_facts' => $targetFacts,
            'items' => array_map(static fn (array $item): array => [
                'comparable_set_item_id' => $item['comparable_set_item_id'],
                'comparable_record_id' => $item['comparable_record_id'],
                'decision' => $item['decision'],
                'original_amount_minor' => $item['original_amount_minor'],
                'original_currency_code' => $item['original_currency_code'],
                'target_amount_minor' => $item['target_amount_minor'],
                'target_currency_code' => $item['target_currency_code'],
                'weight_basis_points' => $item['weight_basis_points'],
                'exchange_rate_id' => $item['exchange_rate_id'],
                'rate_direction' => $item['rate_direction'],
                'rate_value' => $item['rate_value'],
                'rate_effective_at' => $item['rate_effective_at']?->toIso8601String(),
                'reason_codes' => $item['reason_codes'],
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
            $hashInput,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return [
            hash('sha256', $encoded),
            [
                ...$hashInput,
                'calculation_at' => $calculationAt->toIso8601String(),
            ],
        ];
    }

    /** @return list<string> */
    private function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return collect($values)
            ->filter(static fn (mixed $value): bool => is_string($value))
            ->values()
            ->all();
    }
}
