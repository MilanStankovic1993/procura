<?php

namespace App\ProductMatching\Data;

use App\Enums\Catalog\ProductMatchReviewStatus;
use App\Enums\Catalog\ProductMatchStatus;
use InvalidArgumentException;

final readonly class ProductMatchData
{
    /**
     * @param  list<array<string, mixed>>  $candidates
     * @param  list<string>  $reasonCodes
     */
    public function __construct(
        public ProductMatchStatus $status,
        public ProductMatchReviewStatus $reviewStatus,
        public string $method,
        public string $matcherVersion,
        public string $inputHash,
        public int $confidenceBasisPoints,
        public ?string $productModelId,
        public ?string $productVariantId,
        public array $candidates,
        public array $reasonCodes,
    ) {
        if ($confidenceBasisPoints < 0 || $confidenceBasisPoints > 10000) {
            throw new InvalidArgumentException(
                'Product match confidence must be between 0 and 10000 basis points.',
            );
        }

        if ($productVariantId !== null && $productModelId === null) {
            throw new InvalidArgumentException(
                'A selected product variant requires its canonical product model.',
            );
        }

        if ($status === ProductMatchStatus::Matched && $productModelId === null) {
            throw new InvalidArgumentException(
                'An automatic product match requires a canonical product model.',
            );
        }

        if (
            $status === ProductMatchStatus::Matched
            && $reviewStatus !== ProductMatchReviewStatus::NotRequired
        ) {
            throw new InvalidArgumentException(
                'An automatic product match cannot have a pending review.',
            );
        }

        if (
            $status === ProductMatchStatus::Unmatched
            && ($productModelId !== null || $productVariantId !== null)
        ) {
            throw new InvalidArgumentException(
                'An unmatched result cannot silently select a canonical product.',
            );
        }

        foreach ($reasonCodes as $reasonCode) {
            if (! is_string($reasonCode) || trim($reasonCode) === '') {
                throw new InvalidArgumentException(
                    'Product match reason codes must be non-empty strings.',
                );
            }
        }
    }
}
