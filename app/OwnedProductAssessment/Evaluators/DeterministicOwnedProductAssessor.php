<?php

namespace App\OwnedProductAssessment\Evaluators;

use App\Catalog\CatalogTextNormalizer;
use App\Enums\Catalog\ProductMatchStatus;
use App\Enums\OwnedProducts\OwnedProductAssessmentStatus;
use App\Enums\OwnedProducts\OwnedProductCondition;
use App\Models\ProductModel;
use App\Models\ProductVariant;
use App\OwnedProductAssessment\Contracts\OwnedProductAssessor;
use App\OwnedProductAssessment\Data\OwnedProductAssessmentData;
use App\OwnedProductAssessment\Data\OwnedProductAssessmentInputData;
use App\ProductMatching\Contracts\ProductMatcher;
use App\ProductMatching\Data\ProductMatchingInputData;

class DeterministicOwnedProductAssessor implements OwnedProductAssessor
{
    public function __construct(
        private readonly ProductMatcher $matcher,
    ) {}

    public function assess(
        OwnedProductAssessmentInputData $input,
    ): OwnedProductAssessmentData {
        $targetCountryCode = $input->targetCountryCodes[0];
        $match = $this->matcher->match(new ProductMatchingInputData(
            inputHash: $input->inputHash,
            title: $input->searchableTitle(),
            description: null,
            marketCountryCodes: $input->targetCountryCodes,
            targetCountryCode: $targetCountryCode,
        ));
        $model = $match->productModelId === null
            ? null
            : ProductModel::query()
                ->with(['brand', 'category'])
                ->findOrFail($match->productModelId);
        $variant = $match->productVariantId === null
            ? null
            : ProductVariant::query()
                ->with('marketContexts')
                ->findOrFail($match->productVariantId);
        [$missingAccessories, $accessoryReason, $accessoryAction] =
            $this->missingAccessories($input, $variant, $targetCountryCode);
        $unknownFacts = $this->unknownFacts($input, $missingAccessories);
        $verificationActions = $this->verificationActions($input, $match->status);
        $reasonCodes = [
            ...$match->reasonCodes,
            ...$this->evidenceReasonCodes($input),
        ];

        if ($accessoryReason !== null) {
            $reasonCodes[] = $accessoryReason;
        }

        if ($accessoryAction !== null) {
            $verificationActions[] = $accessoryAction;
        }

        $reasonCodes = array_values(array_unique($reasonCodes));
        $unknownFacts = array_values(array_unique($unknownFacts));
        $verificationActions = array_values(array_unique($verificationActions));
        $completeness = $this->completeness($input);
        $confidence = (int) round(
            ($match->confidenceBasisPoints * 0.6) + ($completeness * 0.4),
        );
        $status = match ($match->status) {
            ProductMatchStatus::ReviewRequired => OwnedProductAssessmentStatus::ReviewRequired,
            ProductMatchStatus::Unmatched => OwnedProductAssessmentStatus::NeedsInput,
            ProductMatchStatus::Matched => (
                $input->condition !== OwnedProductCondition::Unknown
                && $input->accessories !== null
                && $input->defects !== null
            )
                ? OwnedProductAssessmentStatus::Ready
                : OwnedProductAssessmentStatus::NeedsInput,
        };

        return new OwnedProductAssessmentData(
            status: $status,
            match: $match,
            evaluatorVersion: (string) config(
                'owned_product_assessment.evaluator_version',
            ),
            confidenceBasisPoints: $confidence,
            completenessBasisPoints: $completeness,
            productCategoryId: $model?->product_category_id,
            identifiedBrandName: $model?->brand?->name,
            identifiedModelName: $model?->name,
            identifiedVariantName: $variant?->name,
            includedAccessories: $input->accessories,
            missingAccessories: $missingAccessories,
            defects: $input->defects,
            reasonCodes: $reasonCodes,
            unknownFacts: $unknownFacts,
            verificationActions: $verificationActions,
        );
    }

    /**
     * @return array{list<string>|null, string|null, string|null}
     */
    private function missingAccessories(
        OwnedProductAssessmentInputData $input,
        ?ProductVariant $variant,
        string $targetCountryCode,
    ): array {
        if ($input->accessories === null) {
            return [null, 'accessories_unchecked', 'check_accessories'];
        }

        $expected = $variant?->marketContexts
            ->firstWhere('country_code', $targetCountryCode)
            ?->included_accessories;

        if (! is_array($expected)) {
            return [
                null,
                'expected_accessory_baseline_unavailable',
                'verify_expected_accessories',
            ];
        }

        $provided = array_map(
            static fn (string $item): string => CatalogTextNormalizer::normalize($item),
            $input->accessories,
        );
        $missing = array_values(array_filter(
            $expected,
            static fn (string $item): bool => ! in_array(
                CatalogTextNormalizer::normalize($item),
                $provided,
                true,
            ),
        ));

        return $missing === []
            ? [[], null, null]
            : [$missing, 'expected_accessories_missing', 'confirm_missing_accessories'];
    }

    /** @return list<string> */
    private function unknownFacts(
        OwnedProductAssessmentInputData $input,
        ?array $missingAccessories,
    ): array {
        $unknown = [];

        if ($input->categoryName === null) {
            $unknown[] = 'category';
        }
        if ($input->brandName === null) {
            $unknown[] = 'brand';
        }
        if ($input->modelName === null) {
            $unknown[] = 'model';
        }
        if ($input->condition === OwnedProductCondition::Unknown) {
            $unknown[] = 'condition';
        }
        if ($input->accessories === null) {
            $unknown[] = 'included_accessories';
        }
        if ($missingAccessories === null) {
            $unknown[] = 'missing_accessories';
        }
        if ($input->defects === null) {
            $unknown[] = 'defects';
        }

        return $unknown;
    }

    /** @return list<string> */
    private function evidenceReasonCodes(
        OwnedProductAssessmentInputData $input,
    ): array {
        $reasons = [];

        if ($input->condition === OwnedProductCondition::Unknown) {
            $reasons[] = 'condition_unknown';
        }
        if ($input->defects === null) {
            $reasons[] = 'defects_unchecked';
        }
        if ($input->imageCount('product') === 0) {
            $reasons[] = 'product_images_missing';
        }
        if ($input->imageCount('serial_label') === 0) {
            $reasons[] = 'serial_label_missing';
        }

        return $reasons;
    }

    /** @return list<string> */
    private function verificationActions(
        OwnedProductAssessmentInputData $input,
        ProductMatchStatus $matchStatus,
    ): array {
        $actions = match ($matchStatus) {
            ProductMatchStatus::Matched => [],
            ProductMatchStatus::ReviewRequired => ['confirm_catalog_candidate'],
            ProductMatchStatus::Unmatched => ['provide_identifying_model_evidence'],
        };

        if ($input->condition === OwnedProductCondition::Unknown) {
            $actions[] = 'confirm_condition';
        }
        if ($input->defects === null) {
            $actions[] = 'inspect_defects';
        }
        if ($input->imageCount('product') === 0) {
            $actions[] = 'add_product_photos';
        }
        if ($input->imageCount('serial_label') === 0) {
            $actions[] = 'add_serial_label_photo';
        }

        return $actions;
    }

    private function completeness(OwnedProductAssessmentInputData $input): int
    {
        return ($input->categoryName === null ? 0 : 1000)
            + ($input->brandName === null ? 0 : 1000)
            + ($input->modelName === null ? 0 : 1500)
            + ($input->condition === OwnedProductCondition::Unknown ? 0 : 2000)
            + ($input->accessories === null ? 0 : 1500)
            + ($input->defects === null ? 0 : 1500)
            + ($input->imageCount('product') === 0 ? 0 : 1000)
            + ($input->imageCount('serial_label') === 0 ? 0 : 500);
    }
}
