<?php

namespace App\OwnedProductAssessment\Data;

use App\Enums\OwnedProducts\OwnedProductAssessmentStatus;
use App\ProductMatching\Data\ProductMatchData;
use InvalidArgumentException;

final readonly class OwnedProductAssessmentData
{
    /**
     * @param  list<string>|null  $includedAccessories
     * @param  list<string>|null  $missingAccessories
     * @param  list<string>|null  $defects
     * @param  list<string>  $reasonCodes
     * @param  list<string>  $unknownFacts
     * @param  list<string>  $verificationActions
     */
    public function __construct(
        public OwnedProductAssessmentStatus $status,
        public ProductMatchData $match,
        public string $evaluatorVersion,
        public int $confidenceBasisPoints,
        public int $completenessBasisPoints,
        public ?string $productCategoryId,
        public ?string $identifiedBrandName,
        public ?string $identifiedModelName,
        public ?string $identifiedVariantName,
        public ?array $includedAccessories,
        public ?array $missingAccessories,
        public ?array $defects,
        public array $reasonCodes,
        public array $unknownFacts,
        public array $verificationActions,
    ) {
        foreach (
            [
                'confidence' => $confidenceBasisPoints,
                'completeness' => $completenessBasisPoints,
            ] as $label => $value
        ) {
            if ($value < 0 || $value > 10000) {
                throw new InvalidArgumentException(
                    "Owned-product assessment {$label} must be between 0 and 10000 basis points.",
                );
            }
        }

        foreach (
            [
                'reason code' => $reasonCodes,
                'unknown fact' => $unknownFacts,
                'verification action' => $verificationActions,
            ] as $label => $values
        ) {
            foreach ($values as $value) {
                if (! is_string($value) || trim($value) === '') {
                    throw new InvalidArgumentException(
                        "Owned-product assessment {$label} values must be non-empty strings.",
                    );
                }
            }
        }
    }
}
