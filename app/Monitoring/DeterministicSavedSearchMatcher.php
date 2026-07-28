<?php

namespace App\Monitoring;

use App\Enums\Monitoring\SavedSearchMatchStatus;
use App\Monitoring\Data\SavedSearchMatchDecision;

final class DeterministicSavedSearchMatcher
{
    /**
     * @param  array<string, mixed>  $criteria
     * @param  array<string, mixed>  $listing
     * @param  array<string, mixed>  $evidence
     */
    public function evaluate(
        array $criteria,
        array $listing,
        array $evidence,
    ): SavedSearchMatchDecision {
        $failed = [];
        $unknown = [];

        $this->evaluatePrice($criteria, $listing, $failed, $unknown);
        $this->evaluateMarket($criteria, $listing, $failed, $unknown);
        $this->evaluateKeywords($criteria, $listing, $failed);
        $this->evaluateProduct($criteria, $evidence, $failed, $unknown);
        $this->evaluateFinancials($criteria, $evidence, $failed, $unknown);

        if ($failed !== []) {
            $status = SavedSearchMatchStatus::NotMatched;
            $reasons = $failed;
        } elseif ($unknown !== []) {
            $status = SavedSearchMatchStatus::InsufficientEvidence;
            $reasons = $unknown;
        } else {
            $status = SavedSearchMatchStatus::Matched;
            $reasons = ['all_configured_criteria_matched'];
        }

        return new SavedSearchMatchDecision(
            status: $status,
            reasonCodes: array_values(array_unique($reasons)),
            unknownCriteria: array_values(array_unique($unknown)),
            evidence: [
                'listing' => $listing,
                'analysis' => $evidence,
                'configured_criteria' => array_keys(array_filter(
                    $criteria,
                    static fn (mixed $value): bool => $value !== null
                        && $value !== []
                        && $value !== false,
                )),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $criteria
     * @param  array<string, mixed>  $listing
     * @param  list<string>  $failed
     * @param  list<string>  $unknown
     */
    private function evaluatePrice(
        array $criteria,
        array $listing,
        array &$failed,
        array &$unknown,
    ): void {
        if (
            $criteria['minimum_price_minor'] === null
            && $criteria['maximum_price_minor'] === null
        ) {
            return;
        }

        if (
            $listing['asking_price_minor'] === null
            || $listing['currency_code'] === null
        ) {
            $unknown[] = 'asking_price_unavailable';

            return;
        }

        if ($listing['currency_code'] !== $criteria['price_currency_code']) {
            $failed[] = 'asking_price_currency_mismatch';

            return;
        }

        if (
            $criteria['minimum_price_minor'] !== null
            && $listing['asking_price_minor']
                < $criteria['minimum_price_minor']
        ) {
            $failed[] = 'asking_price_below_minimum';
        }

        if (
            $criteria['maximum_price_minor'] !== null
            && $listing['asking_price_minor']
                > $criteria['maximum_price_minor']
        ) {
            $failed[] = 'asking_price_above_maximum';
        }
    }

    /**
     * @param  array<string, mixed>  $criteria
     * @param  array<string, mixed>  $listing
     * @param  list<string>  $failed
     * @param  list<string>  $unknown
     */
    private function evaluateMarket(
        array $criteria,
        array $listing,
        array &$failed,
        array &$unknown,
    ): void {
        if (
            $criteria['continent_code'] !== null
            && $listing['source_continent_code'] !== $criteria['continent_code']
        ) {
            $failed[] = 'source_continent_mismatch';
        }

        if (
            $criteria['country_codes'] !== []
            && ! in_array(
                $listing['source_country_code'],
                $criteria['country_codes'],
                true,
            )
        ) {
            $failed[] = 'source_country_mismatch';
        }

        if (
            ! $criteria['include_cross_border']
            && $listing['source_country_code']
                !== $listing['target_country_code']
        ) {
            $failed[] = 'cross_border_listing_excluded';
        }

        if ($criteria['city'] !== null) {
            if ($listing['location'] === null) {
                $unknown[] = 'listing_location_unavailable';
            } elseif (! str_contains(
                $this->text($listing['location']),
                $this->text($criteria['city']),
            )) {
                $failed[] = 'city_mismatch';
            }
        }

        if ($criteria['radius_km'] !== null) {
            $unknown[] = 'geospatial_coordinates_unavailable';
        }
    }

    /**
     * @param  array<string, mixed>  $criteria
     * @param  array<string, mixed>  $listing
     * @param  list<string>  $failed
     */
    private function evaluateKeywords(
        array $criteria,
        array $listing,
        array &$failed,
    ): void {
        $haystack = $this->text(
            trim($listing['title'].' '.($listing['description'] ?? '')),
        );

        foreach ($criteria['required_keywords'] as $keyword) {
            if (! str_contains($haystack, $this->text($keyword))) {
                $failed[] = 'required_keyword_missing';
            }
        }

        foreach ($criteria['excluded_keywords'] as $keyword) {
            if (str_contains($haystack, $this->text($keyword))) {
                $failed[] = 'excluded_keyword_present';
            }
        }
    }

    /**
     * @param  array<string, mixed>  $criteria
     * @param  array<string, mixed>  $evidence
     * @param  list<string>  $failed
     * @param  list<string>  $unknown
     */
    private function evaluateProduct(
        array $criteria,
        array $evidence,
        array &$failed,
        array &$unknown,
    ): void {
        foreach ([
            'product_category_id',
            'brand_id',
            'product_model_id',
        ] as $criterion) {
            if ($criteria[$criterion] === null) {
                continue;
            }

            if ($evidence[$criterion] === null) {
                $unknown[] = 'canonical_product_match_unavailable';
            } elseif ($evidence[$criterion] !== $criteria[$criterion]) {
                $failed[] = str_replace('_id', '_mismatch', $criterion);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $criteria
     * @param  array<string, mixed>  $evidence
     * @param  list<string>  $failed
     * @param  list<string>  $unknown
     */
    private function evaluateFinancials(
        array $criteria,
        array $evidence,
        array &$failed,
        array &$unknown,
    ): void {
        if ($criteria['minimum_profit_minor'] !== null) {
            if (
                $evidence['expected_net_profit_minor'] === null
                || $evidence['profit_currency_code'] === null
            ) {
                $unknown[] = 'profit_estimate_unavailable';
            } elseif (
                $evidence['profit_currency_code']
                !== $criteria['profit_currency_code']
            ) {
                $failed[] = 'profit_currency_mismatch';
            } elseif (
                $evidence['expected_net_profit_minor']
                < $criteria['minimum_profit_minor']
            ) {
                $failed[] = 'expected_profit_below_minimum';
            }
        }

        $this->threshold(
            criteriaValue: $criteria['minimum_margin_basis_points'],
            evidenceValue: $evidence['profit_margin_basis_points'],
            unavailable: 'profit_margin_unavailable',
            failed: 'profit_margin_below_minimum',
            failedReasons: $failed,
            unknownReasons: $unknown,
        );
        $this->threshold(
            criteriaValue: $criteria['minimum_deal_score_basis_points'],
            evidenceValue: $evidence['deal_score_basis_points'],
            unavailable: 'deal_score_unavailable',
            failed: 'deal_score_below_minimum',
            failedReasons: $failed,
            unknownReasons: $unknown,
        );

        if ($criteria['maximum_risk_score'] !== null) {
            if ($evidence['risk_score'] === null) {
                $unknown[] = 'risk_assessment_unavailable';
            } elseif (
                $evidence['risk_score'] > $criteria['maximum_risk_score']
            ) {
                $failed[] = 'risk_score_above_maximum';
            }
        }
    }

    /**
     * @param  list<string>  $failedReasons
     * @param  list<string>  $unknownReasons
     */
    private function threshold(
        ?int $criteriaValue,
        ?int $evidenceValue,
        string $unavailable,
        string $failed,
        array &$failedReasons,
        array &$unknownReasons,
    ): void {
        if ($criteriaValue === null) {
            return;
        }

        if ($evidenceValue === null) {
            $unknownReasons[] = $unavailable;
        } elseif ($evidenceValue < $criteriaValue) {
            $failedReasons[] = $failed;
        }
    }

    private function text(string $value): string
    {
        return preg_replace('/\s+/u', ' ', mb_strtolower(trim($value))) ?? '';
    }
}
