<?php

namespace App\ComparableSelection\Selectors;

use App\ComparableSelection\Contracts\ComparableSelector;
use App\ComparableSelection\Data\ComparableSelectionData;
use App\Enums\Catalog\ProductMatchStatus;
use App\Enums\Comparables\ComparableCondition;
use App\Enums\Comparables\ComparableListingType;
use App\Enums\Comparables\ComparableSetStatus;
use App\Enums\Comparables\MarketCompatibilityStatus;
use App\Models\Analysis;
use App\Models\ComparableMarketNormalization;
use App\Models\ComparableRecord;
use App\Models\ProductMatch;
use Carbon\CarbonImmutable;
use LogicException;

class DeterministicComparableSelector implements ComparableSelector
{
    public function select(
        Analysis $analysis,
        ProductMatch $productMatch,
        array $normalizedListing,
    ): ComparableSelectionData {
        if (
            $productMatch->analysis_id !== $analysis->getKey()
            || $productMatch->organization_id !== $analysis->organization_id
        ) {
            throw new LogicException(
                'The product match does not belong to the comparable selection analysis.',
            );
        }

        if (
            $productMatch->status !== ProductMatchStatus::Matched
            || $productMatch->product_model_id === null
        ) {
            throw new LogicException(
                'Comparable selection requires a confirmed canonical product match.',
            );
        }

        $maximumCandidates = (int) config('comparable_selection.max_candidates');
        $targetCurrency = $this->nullableString(
            $normalizedListing['currency_code']
                ?? $analysis->request_payload['listing']['currency_code']
                ?? null,
        );
        $targetCondition = $this->nullableString(
            $normalizedListing['condition'] ?? null,
        );
        $targetAccessories = $this->normalizedStrings(
            $normalizedListing['included_items'] ?? [],
        );
        $targetSellerType = $this->nullableString(
            $normalizedListing['seller_type'] ?? null,
        );
        $records = ComparableRecord::query()
            ->forOrganization($analysis->organization_id)
            ->where('product_model_id', $productMatch->product_model_id)
            ->with([
                'marketplaceSource:id,key,name,reliability_score',
                'productVariant:id,product_model_id,name,canonical_key',
                'productVariant.marketContexts:id,product_variant_id,country_code',
            ])
            ->orderByDesc('observed_at')
            ->orderBy('id')
            ->limit($maximumCandidates + 1)
            ->get();
        $truncated = $records->count() > $maximumCandidates;
        $records = $records->take($maximumCandidates)->values();
        $normalizations = ComparableMarketNormalization::query()
            ->forOrganization($analysis->organization_id)
            ->where('analysis_id', $analysis->getKey())
            ->whereIn('comparable_record_id', $records->pluck('id'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->unique('comparable_record_id')
            ->keyBy('comparable_record_id');
        $seenSourceIdentities = [];
        $included = [];
        $excluded = [];

        foreach ($records as $record) {
            $normalization = $normalizations->get($record->getKey());
            $exclusionReasons = $this->exclusionReasons(
                $record,
                $productMatch,
                $analysis->target_country_code,
                $targetCurrency,
                $seenSourceIdentities,
                $normalization,
            );
            $seenSourceIdentities[$record->source_identity_hash] = true;
            $evidence = $this->evidenceSnapshot($record, $normalization);

            if ($exclusionReasons !== []) {
                $excluded[] = [
                    'comparable_record_id' => $record->getKey(),
                    'score_basis_points' => 0,
                    'factor_scores' => [],
                    'reason_codes' => $exclusionReasons,
                    'evidence_snapshot' => $evidence,
                ];

                continue;
            }

            $factors = $this->factorScores(
                $record,
                $productMatch,
                $analysis->target_country_code,
                $targetCondition,
                $targetAccessories,
                $targetSellerType,
                $normalization,
            );
            $included[] = [
                'comparable_record_id' => $record->getKey(),
                'score_basis_points' => array_sum($factors),
                'factor_scores' => $factors,
                'reason_codes' => $this->rankingEvidence(
                    $record,
                    $productMatch,
                    $targetCondition,
                    $targetAccessories,
                    $targetSellerType,
                    $normalization,
                ),
                'evidence_snapshot' => $evidence,
            ];
        }

        usort($included, static function (array $left, array $right): int {
            return [
                $right['score_basis_points'],
                $right['evidence_snapshot']['observed_at'],
                $left['comparable_record_id'],
            ] <=> [
                $left['score_basis_points'],
                $left['evidence_snapshot']['observed_at'],
                $right['comparable_record_id'],
            ];
        });

        $maximumSelected = (int) config('comparable_selection.max_selected');
        $overflow = array_splice($included, $maximumSelected);

        foreach ($overflow as $item) {
            $excluded[] = [
                ...$item,
                'score_basis_points' => 0,
                'factor_scores' => [],
                'reason_codes' => ['selection_limit_reached'],
            ];
        }

        $minimumRequired = (int) config('comparable_selection.minimum_selected');
        $status = count($included) >= $minimumRequired
            ? ComparableSetStatus::Ready
            : ComparableSetStatus::Insufficient;
        $reasonCodes = match (true) {
            $status === ComparableSetStatus::Ready => ['minimum_comparable_count_met'],
            $included === [] => ['no_comparable_records'],
            default => ['insufficient_comparable_records'],
        };

        if ($truncated) {
            $reasonCodes[] = 'candidate_pool_truncated';
        }

        $input = [
            'analysis_id' => $analysis->getKey(),
            'product_match_id' => $productMatch->getKey(),
            'product_match_input_hash' => $productMatch->input_hash,
            'product_model_id' => $productMatch->product_model_id,
            'product_variant_id' => $productMatch->product_variant_id,
            'target_country_code' => $analysis->target_country_code,
            'target_currency_code' => $targetCurrency,
            'target_condition' => $targetCondition,
            'target_accessories' => $targetAccessories,
            'target_seller_type' => $targetSellerType,
            'candidate_evidence' => $records->map(
                static fn (ComparableRecord $record): array => [
                    'id' => $record->getKey(),
                    'evidence_hash' => $record->evidence_hash,
                    'market_normalization_evidence_hash' => $normalizations
                        ->get($record->getKey())
                        ?->evidence_hash,
                ],
            )->all(),
        ];
        $encodedInput = json_encode(
            $input,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return new ComparableSelectionData(
            status: $status,
            selectorVersion: (string) config('comparable_selection.selector_version'),
            inputHash: hash('sha256', $encodedInput),
            targetCountryCode: $analysis->target_country_code,
            targetCurrencyCode: $targetCurrency,
            candidateCount: count($included) + count($excluded),
            minimumRequired: $minimumRequired,
            included: array_values($included),
            excluded: array_values($excluded),
            reasonCodes: array_values(array_unique($reasonCodes)),
        );
    }

    /**
     * @param  array<string, bool>  $seenSourceIdentities
     * @return list<string>
     */
    private function exclusionReasons(
        ComparableRecord $record,
        ProductMatch $productMatch,
        string $targetCountryCode,
        ?string $targetCurrencyCode,
        array $seenSourceIdentities,
        ?ComparableMarketNormalization $normalization,
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
        $requiresCurrencyNormalization = $targetCurrencyCode !== null
            && $record->currency_code !== $targetCurrencyCode;
        $requiresNormalization = $requiresCrossCountryNormalization
            || $requiresCurrencyNormalization;

        if ($targetCurrencyCode === null) {
            $reasons[] = 'target_currency_unavailable';
        } elseif ($requiresNormalization && $normalization === null) {
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
            $productMatch->product_variant_id !== null
            && $record->product_variant_id !== null
            && $record->product_variant_id !== $productMatch->product_variant_id
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

    /**
     * @param  list<string>  $targetAccessories
     * @return array<string, int>
     */
    private function factorScores(
        ComparableRecord $record,
        ProductMatch $productMatch,
        string $targetCountryCode,
        ?string $targetCondition,
        array $targetAccessories,
        ?string $targetSellerType,
        ?ComparableMarketNormalization $normalization,
    ): array {
        $conditionScore = $targetCondition !== null
            && $targetCondition !== ComparableCondition::Unknown->value
            && $record->condition_code->value === $targetCondition
                ? 1500
                : 0;
        $sellerScore = $targetSellerType !== null
            && $record->seller_type->value === $targetSellerType
                ? 400
                : 0;
        $reliabilityScore = (int) round(
            400 * (
                min(10000, max(0, $record->source_reliability_basis_points))
                / 10000
            ),
        );

        return [
            'exact_model' => 3000,
            'exact_variant' => $this->variantScore($record, $productMatch),
            'condition_similarity' => $conditionScore,
            'accessory_similarity' => $this->accessoryScore(
                $targetAccessories,
                $this->normalizedStrings($record->included_accessories),
            ),
            'geographic_relevance' => $record->country_code === $targetCountryCode
                ? 1000
                : ($normalization?->compatibility_status
                    === MarketCompatibilityStatus::Compatible ? 500 : 0),
            'time_relevance' => $this->timeScore($record->observed_at),
            'seller_type_relevance' => $sellerScore,
            'source_reliability' => $reliabilityScore,
        ];
    }

    private function variantScore(
        ComparableRecord $record,
        ProductMatch $productMatch,
    ): int {
        if ($productMatch->product_variant_id !== null) {
            return $record->product_variant_id === $productMatch->product_variant_id
                ? 2000
                : 0;
        }

        return $record->product_variant_id === null ? 1000 : 500;
    }

    /**
     * @param  list<string>  $target
     * @param  list<string>  $candidate
     */
    private function accessoryScore(array $target, array $candidate): int
    {
        if ($target === [] || $candidate === []) {
            return 0;
        }

        $union = array_unique([...$target, ...$candidate]);
        $intersection = array_intersect($target, $candidate);

        return (int) round(1000 * (count($intersection) / count($union)));
    }

    private function timeScore(CarbonImmutable $observedAt): int
    {
        $days = max(0, $observedAt->diffInDays(now(), absolute: true));

        return match (true) {
            $days <= 30 => 700,
            $days <= 90 => 600,
            $days <= 180 => 450,
            $days <= 365 => 300,
            $days <= 730 => 100,
            default => 0,
        };
    }

    /**
     * @param  list<string>  $targetAccessories
     * @return list<string>
     */
    private function rankingEvidence(
        ComparableRecord $record,
        ProductMatch $productMatch,
        ?string $targetCondition,
        array $targetAccessories,
        ?string $targetSellerType,
        ?ComparableMarketNormalization $normalization,
    ): array {
        $reasons = ['exact_model'];
        $reasons[] = $normalization === null
            || $record->country_code === $normalization->target_country_code
                ? 'same_country'
                : 'cross_country_normalized';
        $reasons[] = $normalization === null
            || $record->currency_code === $normalization->target_currency_code
                ? 'same_currency'
                : 'dated_currency_normalized';
        $reasons[] = match (true) {
            $productMatch->product_variant_id !== null
                && $record->product_variant_id === $productMatch->product_variant_id => 'exact_variant',
            $record->product_variant_id === null => 'variant_unspecified',
            default => 'variant_not_required_by_match',
        };

        if ($targetCondition === null || $targetCondition === ComparableCondition::Unknown->value) {
            $reasons[] = 'target_condition_unavailable';
        } elseif ($record->condition_code->value === $targetCondition) {
            $reasons[] = 'exact_condition';
        } else {
            $reasons[] = 'condition_differs';
        }

        $reasons[] = $targetAccessories === []
            ? 'target_accessories_unavailable'
            : 'accessories_compared';
        $reasons[] = $targetSellerType === null
            ? 'target_seller_type_unavailable'
            : 'seller_type_compared';

        return $reasons;
    }

    /** @return array<string, mixed> */
    private function evidenceSnapshot(
        ComparableRecord $record,
        ?ComparableMarketNormalization $normalization,
    ): array {
        return [
            'id' => $record->getKey(),
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
        ComparableMarketNormalization $normalization,
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
            ->map(static fn (string $value): string => str($value)->lower()->squish()->toString())
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
