<?php

namespace App\SellPriceIntelligence\Selectors;

use App\Enums\Comparables\ComparableCondition;
use App\Enums\Comparables\ComparableListingType;
use App\Enums\Comparables\ComparableSetStatus;
use App\Enums\Comparables\MarketCompatibilityStatus;
use App\Models\OwnedProduct;
use App\Models\OwnedProductAssessment;
use App\Models\SellComparableMarketNormalization;
use App\Models\SellComparableRecord;
use App\SellPriceIntelligence\Data\SellComparableSelectionData;
use Carbon\CarbonImmutable;
use LogicException;

final class DeterministicSellComparableSelector
{
    public function select(
        OwnedProduct $ownedProduct,
        OwnedProductAssessment $assessment,
        string $targetCountryCode,
        string $targetCurrencyCode,
    ): SellComparableSelectionData {
        if (
            $assessment->owned_product_id !== $ownedProduct->getKey()
            || $assessment->organization_id !== $ownedProduct->organization_id
            || $assessment->product_model_id === null
        ) {
            throw new LogicException(
                'Sell comparable selection requires a matching assessed product.',
            );
        }

        $maximumCandidates = (int) config(
            'sell_price_intelligence.max_candidates',
        );
        $records = SellComparableRecord::query()
            ->forOrganization($ownedProduct->organization_id)
            ->where(
                'owned_product_assessment_id',
                $assessment->getKey(),
            )
            ->where('product_model_id', $assessment->product_model_id)
            ->with([
                'marketplaceSource:id,key,name,reliability_score',
                'productVariant:id,product_model_id,name,canonical_key',
                'productVariant.marketContexts:id,product_variant_id,country_code',
            ])
            ->orderByRaw(
                'case when country_code = ? and currency_code = ? then 0 else 1 end',
                [$targetCountryCode, $targetCurrencyCode],
            )
            ->orderByDesc('observed_at')
            ->orderBy('id')
            ->limit($maximumCandidates + 1)
            ->get();
        $truncated = $records->count() > $maximumCandidates;
        $records = $records->take($maximumCandidates)->values();
        $normalizations = SellComparableMarketNormalization::query()
            ->forOrganization($ownedProduct->organization_id)
            ->where(
                'owned_product_assessment_id',
                $assessment->getKey(),
            )
            ->whereIn('sell_comparable_record_id', $records->pluck('id'))
            ->where('target_country_code', $targetCountryCode)
            ->where('target_currency_code', $targetCurrencyCode)
            ->orderByDesc('observed_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->unique('sell_comparable_record_id')
            ->keyBy('sell_comparable_record_id');
        $referenceAt = $records
            ->pluck('observed_at')
            ->filter()
            ->push($assessment->assessed_at)
            ->sortDesc()
            ->first() ?? CarbonImmutable::parse($assessment->created_at);
        $seenSourceIdentities = [];
        $included = [];
        $excluded = [];

        foreach ($records as $record) {
            $normalization = $normalizations->get($record->getKey());
            $exclusionReasons = $this->exclusionReasons(
                $record,
                $assessment,
                $targetCountryCode,
                $targetCurrencyCode,
                $seenSourceIdentities,
                $normalization,
            );
            $seenSourceIdentities[$record->source_identity_hash] = true;
            $evidence = $this->evidenceSnapshot($record, $normalization);

            if ($exclusionReasons !== []) {
                $excluded[] = [
                    'sell_comparable_record_id' => $record->getKey(),
                    'score_basis_points' => 0,
                    'factor_scores' => [],
                    'reason_codes' => $exclusionReasons,
                    'evidence_snapshot' => $evidence,
                ];

                continue;
            }

            $factors = $this->factorScores(
                $record,
                $assessment,
                $referenceAt,
                $targetCountryCode,
                $normalization,
            );
            $included[] = [
                'sell_comparable_record_id' => $record->getKey(),
                'score_basis_points' => min(10000, array_sum($factors)),
                'factor_scores' => $factors,
                'reason_codes' => $this->rankingEvidence(
                    $record,
                    $assessment,
                    $normalization,
                ),
                'evidence_snapshot' => $evidence,
            ];
        }

        usort($included, static fn (array $left, array $right): int => [
            $right['score_basis_points'],
            $right['evidence_snapshot']['observed_at'],
            $left['sell_comparable_record_id'],
        ] <=> [
            $left['score_basis_points'],
            $left['evidence_snapshot']['observed_at'],
            $right['sell_comparable_record_id'],
        ]);

        $overflow = array_splice(
            $included,
            (int) config('sell_price_intelligence.max_selected'),
        );

        foreach ($overflow as $item) {
            $excluded[] = [
                ...$item,
                'score_basis_points' => 0,
                'factor_scores' => [],
                'reason_codes' => ['selection_limit_reached'],
            ];
        }

        $minimumRequired = (int) config(
            'sell_price_intelligence.minimum_selected',
        );
        $status = count($included) >= $minimumRequired
            ? ComparableSetStatus::Ready
            : ComparableSetStatus::Insufficient;
        $reasonCodes = $status === ComparableSetStatus::Ready
            ? ['minimum_comparable_count_met', 'explicit_market_normalization_only']
            : [
                count($included) === 0
                    ? 'no_eligible_sell_comparables'
                    : 'insufficient_sell_comparables',
                'explicit_market_normalization_only',
            ];

        if ($truncated) {
            $reasonCodes[] = 'candidate_pool_truncated';
        }

        $inputSnapshot = [
            'owned_product_id' => $ownedProduct->getKey(),
            'owned_product_assessment_id' => $assessment->getKey(),
            'assessment_input_hash' => $assessment->input_hash,
            'product_model_id' => $assessment->product_model_id,
            'product_variant_id' => $assessment->product_variant_id,
            'target_country_code' => $targetCountryCode,
            'target_currency_code' => $targetCurrencyCode,
            'target_condition' => $assessment->condition->value,
            'target_accessories' => $this->normalizedStrings(
                $assessment->included_accessories,
            ),
            'reference_at' => $referenceAt->toIso8601String(),
            'selector_version' => config(
                'sell_price_intelligence.selector_version',
            ),
            'candidate_evidence' => $records
                ->map(static fn (SellComparableRecord $record): array => [
                    'id' => $record->getKey(),
                    'evidence_hash' => $record->evidence_hash,
                    'market_normalization_evidence_hash' => $normalizations
                        ->get($record->getKey())
                        ?->evidence_hash,
                ])
                ->all(),
        ];
        $encodedInput = json_encode(
            $inputSnapshot,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return new SellComparableSelectionData(
            status: $status,
            selectorVersion: (string) config(
                'sell_price_intelligence.selector_version',
            ),
            inputHash: hash('sha256', $encodedInput),
            targetCountryCode: $targetCountryCode,
            targetCurrencyCode: $targetCurrencyCode,
            candidateCount: count($included) + count($excluded),
            minimumRequired: $minimumRequired,
            included: array_values($included),
            excluded: array_values($excluded),
            reasonCodes: array_values(array_unique($reasonCodes)),
            inputSnapshot: $inputSnapshot,
        );
    }

    /**
     * @param  array<string, bool>  $seenSourceIdentities
     * @return list<string>
     */
    private function exclusionReasons(
        SellComparableRecord $record,
        OwnedProductAssessment $assessment,
        string $targetCountryCode,
        string $targetCurrencyCode,
        array $seenSourceIdentities,
        ?SellComparableMarketNormalization $normalization,
    ): array {
        $reasons = [];

        if (isset($seenSourceIdentities[$record->source_identity_hash])) {
            $reasons[] = 'superseded_source_observation';
        }

        if ($record->listing_type !== ComparableListingType::Product) {
            $reasons[] = match ($record->listing_type) {
                ComparableListingType::SparePart => 'spare_part_listing',
                ComparableListingType::BrokenOnly => 'broken_only_listing',
                ComparableListingType::Wanted => 'wanted_listing',
                ComparableListingType::Rental => 'rental_listing',
                ComparableListingType::UnclearBundle => 'unclear_bundle_listing',
                ComparableListingType::Product => throw new LogicException(
                    'A product listing cannot have an exclusion-only type.',
                ),
            };
        }

        if ($record->condition_code === ComparableCondition::Broken) {
            $reasons[] = 'broken_condition';
        }

        $requiresCrossCountryNormalization = $record->country_code
            !== $targetCountryCode;
        $requiresCurrencyNormalization = $record->currency_code
            !== $targetCurrencyCode;
        $requiresNormalization = $requiresCrossCountryNormalization
            || $requiresCurrencyNormalization;

        if ($requiresNormalization && $normalization === null) {
            if ($requiresCurrencyNormalization) {
                $reasons[] = 'currency_conversion_unavailable';
            }

            if ($requiresCrossCountryNormalization) {
                $reasons[] = 'cross_country_normalization_unavailable';
            }
        } elseif (
            $requiresNormalization
            && $normalization?->compatibility_status
                === MarketCompatibilityStatus::Incompatible
        ) {
            $reasons[] = 'market_compatibility_rejected';
        } elseif (
            $requiresNormalization
            && (
                $normalization?->normalized_amount_minor === null
                || $normalization->source_country_code !== $record->country_code
                || $normalization->source_currency_code !== $record->currency_code
                || $normalization->source_amount_minor !== $record->asking_price_minor
                || $normalization->target_country_code !== $targetCountryCode
                || $normalization->target_currency_code !== $targetCurrencyCode
            )
        ) {
            $reasons[] = 'market_normalization_evidence_invalid';
        }

        if (
            $assessment->product_variant_id !== null
            && $record->product_variant_id !== null
            && $record->product_variant_id !== $assessment->product_variant_id
        ) {
            $reasons[] = 'incompatible_variant';
        }

        if (
            $record->productVariant !== null
            && $record->productVariant->marketContexts->isNotEmpty()
            && ! $record->productVariant->marketContexts->contains(
                'country_code',
                $targetCountryCode,
            )
        ) {
            $reasons[] = 'region_incompatible_variant';
        }

        return array_values(array_unique($reasons));
    }

    /** @return array<string, int> */
    private function factorScores(
        SellComparableRecord $record,
        OwnedProductAssessment $assessment,
        CarbonImmutable $referenceAt,
        string $targetCountryCode,
        ?SellComparableMarketNormalization $normalization,
    ): array {
        $targetAccessories = $this->normalizedStrings(
            $assessment->included_accessories,
        );
        $conditionScore = $assessment->condition->value !== 'unknown'
            && $record->condition_code->value === $assessment->condition->value
                ? 1500
                : 0;
        $reliabilityScore = (int) round(
            500 * (
                min(10000, max(0, $record->source_reliability_basis_points))
                / 10000
            ),
        );

        return [
            'exact_model' => 3000,
            'exact_variant' => $this->variantScore($record, $assessment),
            'condition_similarity' => $conditionScore,
            'accessory_similarity' => $this->accessoryScore(
                $targetAccessories,
                $this->normalizedStrings($record->included_accessories),
            ),
            'geographic_relevance' => $record->country_code
                === $targetCountryCode
                ? 1000
                : ($normalization === null ? 0 : 700),
            'time_relevance' => $this->timeScore(
                $record->observed_at,
                $referenceAt,
            ),
            'source_reliability' => $reliabilityScore,
        ];
    }

    private function variantScore(
        SellComparableRecord $record,
        OwnedProductAssessment $assessment,
    ): int {
        if ($assessment->product_variant_id !== null) {
            return $record->product_variant_id === $assessment->product_variant_id
                ? 2000
                : 0;
        }

        return $record->product_variant_id === null ? 1000 : 500;
    }

    /** @param list<string> $target @param list<string> $candidate */
    private function accessoryScore(array $target, array $candidate): int
    {
        if ($target === [] || $candidate === []) {
            return 0;
        }

        $union = array_unique([...$target, ...$candidate]);
        $intersection = array_intersect($target, $candidate);

        return (int) round(1000 * (count($intersection) / count($union)));
    }

    private function timeScore(
        CarbonImmutable $observedAt,
        CarbonImmutable $referenceAt,
    ): int {
        $days = max(0, $observedAt->diffInDays($referenceAt, absolute: true));

        return match (true) {
            $days <= 30 => 1000,
            $days <= 90 => 800,
            $days <= 180 => 600,
            $days <= 365 => 350,
            $days <= 730 => 100,
            default => 0,
        };
    }

    /** @return list<string> */
    private function rankingEvidence(
        SellComparableRecord $record,
        OwnedProductAssessment $assessment,
        ?SellComparableMarketNormalization $normalization,
    ): array {
        $reasons = ['exact_model'];

        if ($normalization === null) {
            $reasons[] = 'same_market';
            $reasons[] = 'same_currency';
        } else {
            $reasons[] = 'explicit_market_normalization_applied';
            $reasons[] = $normalization->source_country_code
                === $normalization->target_country_code
                    ? 'same_market'
                    : 'cross_country_normalized';
            $reasons[] = $normalization->source_currency_code
                === $normalization->target_currency_code
                    ? 'identity_currency_normalized'
                    : 'dated_currency_normalized';
        }
        $reasons[] = match (true) {
            $assessment->product_variant_id !== null
                && $record->product_variant_id === $assessment->product_variant_id => 'exact_variant',
            $record->product_variant_id === null => 'variant_unspecified',
            default => 'variant_not_required_by_assessment',
        };
        $reasons[] = $assessment->condition->value === 'unknown'
            ? 'target_condition_unavailable'
            : (
                $record->condition_code->value === $assessment->condition->value
                    ? 'exact_condition'
                    : 'condition_differs'
            );
        $reasons[] = $assessment->included_accessories === null
            ? 'target_accessories_unavailable'
            : 'accessories_compared';

        return $reasons;
    }

    /** @return array<string, mixed> */
    private function evidenceSnapshot(
        SellComparableRecord $record,
        ?SellComparableMarketNormalization $normalization,
    ): array {
        return [
            'id' => $record->getKey(),
            'assessment_input_hash' => $record->assessment_input_hash,
            'source_key' => $record->marketplaceSource?->key,
            'source_name' => $record->marketplaceSource?->name,
            'source_identity_hash' => $record->source_identity_hash,
            'evidence_hash' => $record->evidence_hash,
            'marketplace_name' => $record->marketplace_name,
            'source_url' => $record->source_url,
            'external_id' => $record->external_id,
            'title' => $record->title,
            'listing_type' => $record->listing_type->value,
            'condition' => $record->condition_code->value,
            'seller_type' => $record->seller_type->value,
            'asking_price_minor' => $record->asking_price_minor,
            'currency_code' => $record->currency_code,
            'country_code' => $record->country_code,
            'included_accessories' => $record->included_accessories,
            'missing_accessories' => $record->missing_accessories,
            'product_variant_id' => $record->product_variant_id,
            'product_variant' => $record->productVariant?->name,
            'source_reliability_basis_points' => $record->source_reliability_basis_points,
            'published_at' => $record->published_at?->toIso8601String(),
            'observed_at' => $record->observed_at->toIso8601String(),
            'market_normalization' => $normalization === null
                ? null
                : $this->normalizationSnapshot($normalization),
        ];
    }

    /** @return array<string, mixed> */
    private function normalizationSnapshot(
        SellComparableMarketNormalization $normalization,
    ): array {
        return [
            'id' => $normalization->getKey(),
            'evidence_hash' => $normalization->evidence_hash,
            'calculation_version' => $normalization->calculation_version,
            'compatibility_status' => $normalization->compatibility_status->value,
            'source_country_code' => $normalization->source_country_code,
            'target_country_code' => $normalization->target_country_code,
            'source_currency_code' => $normalization->source_currency_code,
            'target_currency_code' => $normalization->target_currency_code,
            'source_amount_minor' => $normalization->source_amount_minor,
            'converted_amount_minor' => $normalization->converted_amount_minor,
            'market_factor_basis_points' => $normalization->market_factor_basis_points,
            'market_adjusted_amount_minor' => $normalization->market_adjusted_amount_minor,
            'shipping_minor' => $normalization->shipping_minor,
            'import_duty_minor' => $normalization->import_duty_minor,
            'tax_minor' => $normalization->tax_minor,
            'other_cost_minor' => $normalization->other_cost_minor,
            'normalized_amount_minor' => $normalization->normalized_amount_minor,
            'exchange_rate_id' => $normalization->exchange_rate_id,
            'rate_direction' => $normalization->rate_direction?->value,
            'rate_value' => $normalization->rate_value,
            'rate_effective_at' => $normalization->rate_effective_at?->toIso8601String(),
            'rate_provider' => $normalization->rate_provider,
            'rate_provider_reference' => $normalization->rate_provider_reference,
            'evidence_reference' => $normalization->evidence_reference,
            'compatibility_note' => $normalization->compatibility_note,
            'reason_codes' => $normalization->reason_codes,
            'observed_at' => $normalization->observed_at->toIso8601String(),
            'created_at' => $normalization->created_at?->toIso8601String(),
        ];
    }

    /** @return list<string> */
    private function normalizedStrings(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return collect($values)
            ->filter(static fn (mixed $value): bool => is_string($value))
            ->map(static fn (string $value): string => str($value)
                ->lower()
                ->squish()
                ->toString())
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
