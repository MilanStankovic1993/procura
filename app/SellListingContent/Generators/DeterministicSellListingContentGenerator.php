<?php

namespace App\SellListingContent\Generators;

use App\Enums\Sell\SellPriceBandStatus;
use App\Enums\Sell\SellPriceStrategy;
use App\Models\OwnedProductAssessment;
use App\Models\OwnedProductSnapshot;
use App\Models\SellPriceBand;
use App\SellListingContent\Data\SellListingContentData;
use App\SellListingContent\Data\SellPhotoReadinessData;

final class DeterministicSellListingContentGenerator
{
    public function generate(
        OwnedProductAssessment $assessment,
        OwnedProductSnapshot $snapshot,
        SellPriceBand $priceBand,
        SellPriceStrategy $strategy,
        int $targetAskingPriceMinor,
        int $currencyMinorUnit,
        string $listingLanguage,
        SellPhotoReadinessData $photoReadiness,
        bool $priceOutsideBand,
    ): SellListingContentData {
        $template = $this->template($listingLanguage);
        $identity = $this->identity($assessment, $template);
        $condition = $template['condition'][$assessment->condition->value];
        $price = $this->formatMoney(
            $targetAskingPriceMinor,
            $priceBand->target_currency_code,
            $currencyMinorUnit,
            $listingLanguage,
        );
        $facts = [
            $this->fact(
                1,
                'identity',
                'owned_product_assessment',
                (string) $assessment->getKey(),
                'identified_product',
                false,
                $this->replace($template['intro'], [
                    'identity' => $identity,
                ]),
                [
                    'brand' => $assessment->identified_brand_name,
                    'model' => $assessment->identified_model_name,
                    'variant' => $assessment->identified_variant_name,
                ],
            ),
            $this->fact(
                2,
                'condition',
                'owned_product_assessment',
                (string) $assessment->getKey(),
                'condition',
                $assessment->condition->value === 'unknown',
                $this->replace($template['condition_disclosure'], [
                    'condition' => $condition,
                ]),
                ['condition' => $assessment->condition->value],
            ),
            $this->ageFact($template, $snapshot),
            $this->listFact(
                position: 4,
                code: 'included_accessories',
                values: $assessment->included_accessories,
                templates: $template,
                populatedKey: 'included',
                emptyKey: 'included_none',
                unknownKey: 'included_unknown',
                assessment: $assessment,
            ),
            $this->listFact(
                position: 5,
                code: 'missing_accessories',
                values: $assessment->missing_accessories,
                templates: $template,
                populatedKey: 'missing',
                emptyKey: 'missing_none',
                unknownKey: 'missing_unknown',
                assessment: $assessment,
            ),
            $this->listFact(
                position: 6,
                code: 'defects',
                values: $assessment->defects,
                templates: $template,
                populatedKey: 'defects',
                emptyKey: 'defects_none',
                unknownKey: 'defects_unknown',
                assessment: $assessment,
            ),
            $this->fact(
                7,
                'target_asking_price',
                'sell_price_band',
                (string) $priceBand->getKey(),
                'target_asking_price_minor',
                false,
                $this->replace($template['asking_price'], [
                    'price' => $price,
                ]),
                [
                    'amount_minor' => $targetAskingPriceMinor,
                    'currency_code' => $priceBand->target_currency_code,
                    'price_strategy' => $strategy->value,
                    'outside_selected_band' => $priceOutsideBand,
                ],
            ),
        ];
        $description = implode("\n\n", [
            $facts[0]['disclosure'],
            $template['condition_heading']."\n"
                .$facts[1]['disclosure']."\n"
                .$facts[2]['disclosure'],
            $template['accessories_heading']."\n"
                .$facts[3]['disclosure']."\n"
                .$facts[4]['disclosure'],
            $template['defects_heading']."\n"
                .$facts[5]['disclosure'],
            $template['price_heading']."\n"
                .$facts[6]['disclosure']."\n"
                .$this->replace($template['guidance_note'], [
                    'strategy' => $template['strategy'][$strategy->value],
                ]),
        ]);
        $warnings = ['asking_price_guidance_not_guarantee'];
        $actions = $photoReadiness->verificationActions;

        if ($priceBand->status === SellPriceBandStatus::LowConfidence) {
            $warnings[] = 'low_confidence_price_band';
            $actions[] = 'review_low_confidence_price_band';
        }

        if ($priceOutsideBand) {
            $warnings[] = 'target_price_outside_selected_band';
            $actions[] = 'review_target_price_override';
        }

        $unknownFacts = collect([
            ...($assessment->unknown_facts ?? []),
            ...($priceBand->unknown_facts ?? []),
            'realized_sale_price',
            'time_to_sale',
        ])->unique()->values()->all();
        $verificationActions = collect([
            ...($assessment->verification_actions ?? []),
            ...($priceBand->verification_actions ?? []),
            ...$actions,
        ])->unique()->values()->all();
        $completenessBasisPoints = intdiv(
            ($assessment->completeness_basis_points * 40)
                + ($priceBand->completeness_basis_points * 30)
                + ($photoReadiness->basisPoints * 30)
                + 50,
            100,
        );

        return new SellListingContentData(
            title: mb_substr(
                $this->replace($template['title'], [
                    'identity' => $identity,
                    'condition' => $condition,
                ]),
                0,
                (int) config('sell_listing_content.title_max_length'),
            ),
            description: $description,
            completenessBasisPoints: $completenessBasisPoints,
            facts: $facts,
            reasonCodes: [
                'deterministic_listing_template',
                'listing_language_explicit',
                'source_facts_only',
                "price_strategy_{$strategy->value}",
                ...$photoReadiness->reasonCodes,
            ],
            unknownFacts: $unknownFacts,
            warnings: collect([
                ...$warnings,
                ...$photoReadiness->warnings,
            ])->unique()->values()->all(),
            verificationActions: $verificationActions,
        );
    }

    /**
     * @param  array<string, mixed>  $template
     * @return array<string, mixed>
     */
    private function ageFact(
        array $template,
        OwnedProductSnapshot $snapshot,
    ): array {
        $unknown = $snapshot->age_months === null;

        return $this->fact(
            3,
            'age',
            'owned_product_snapshot',
            (string) $snapshot->getKey(),
            'age_months',
            $unknown,
            $unknown
                ? $template['age_unknown']
                : $this->replace($template['age'], [
                    'age_months' => (string) $snapshot->age_months,
                ]),
            ['age_months' => $snapshot->age_months],
        );
    }

    /**
     * @param  list<string>|null  $values
     * @param  array<string, mixed>  $templates
     * @return array<string, mixed>
     */
    private function listFact(
        int $position,
        string $code,
        ?array $values,
        array $templates,
        string $populatedKey,
        string $emptyKey,
        string $unknownKey,
        OwnedProductAssessment $assessment,
    ): array {
        $unknown = $values === null;
        $disclosure = $unknown
            ? $templates[$unknownKey]
            : ($values === []
                ? $templates[$emptyKey]
                : $this->replace($templates[$populatedKey], [
                    'values' => implode(', ', $values),
                ]));

        return $this->fact(
            $position,
            $code,
            'owned_product_assessment',
            (string) $assessment->getKey(),
            $code,
            $unknown,
            $disclosure,
            ['values' => $values],
        );
    }

    /**
     * @param  array<string, mixed>  $valueSnapshot
     * @return array<string, mixed>
     */
    private function fact(
        int $position,
        string $code,
        string $sourceKind,
        string $sourceId,
        string $sourceField,
        bool $isUnknown,
        string $disclosure,
        array $valueSnapshot,
    ): array {
        return [
            'position' => $position,
            'fact_code' => $code,
            'source_kind' => $sourceKind,
            'source_id' => $sourceId,
            'source_field' => $sourceField,
            'is_unknown' => $isUnknown,
            'disclosure' => $disclosure,
            'value_snapshot' => $valueSnapshot,
        ];
    }

    /**
     * @param  array<string, mixed>  $template
     */
    private function identity(
        OwnedProductAssessment $assessment,
        array $template,
    ): string {
        $parts = collect([
            $assessment->identified_brand_name,
            $assessment->identified_model_name,
            $assessment->identified_variant_name,
        ])->filter(static fn (?string $part): bool => (
            $part !== null && trim($part) !== ''
        ))->unique(static fn (string $part): string => mb_strtolower($part));

        return $parts->isEmpty()
            ? $template['product_fallback']
            : $parts->implode(' ');
    }

    private function formatMoney(
        int $amountMinor,
        string $currencyCode,
        int $minorUnit,
        string $listingLanguage,
    ): string {
        $divisor = 10 ** $minorUnit;
        $whole = intdiv($amountMinor, $divisor);

        if ($minorUnit === 0) {
            return "{$whole} {$currencyCode}";
        }

        $separator = $listingLanguage === 'en' ? '.' : ',';
        $fraction = str_pad(
            (string) ($amountMinor % $divisor),
            $minorUnit,
            '0',
            STR_PAD_LEFT,
        );

        return "{$whole}{$separator}{$fraction} {$currencyCode}";
    }

    /**
     * @return array<string, mixed>
     */
    private function template(string $listingLanguage): array
    {
        $path = resource_path(
            "sell-listing-templates/{$listingLanguage}.php",
        );

        return require $path;
    }

    /**
     * @param  array<string, string>  $values
     */
    private function replace(string $template, array $values): string
    {
        return strtr(
            $template,
            collect($values)
                ->mapWithKeys(static fn (
                    string $value,
                    string $key,
                ): array => ["{{$key}}" => $value])
                ->all(),
        );
    }
}
