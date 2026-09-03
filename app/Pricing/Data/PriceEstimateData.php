<?php

namespace App\Pricing\Data;

use App\Enums\Pricing\PriceConfidenceLevel;
use App\Enums\Pricing\PriceEstimateStatus;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class PriceEstimateData
{
    /**
     * @param  list<string>  $reasonCodes
     * @param  array<string, int>  $confidenceComponents
     * @param  array<string, mixed>  $inputSnapshot
     * @param  list<array<string, mixed>>  $items
     */
    public function __construct(
        public PriceEstimateStatus $status,
        public string $algorithmVersion,
        public string $rateResolverVersion,
        public string $inputHash,
        public CarbonImmutable $calculationAt,
        public string $targetCountryCode,
        public string $targetCurrencyCode,
        public int $inputCount,
        public int $includedCount,
        public int $outlierCount,
        public int $unresolvedCount,
        public ?int $estimateLowMinor,
        public ?int $estimateMinor,
        public ?int $estimateHighMinor,
        public ?int $medianMinor,
        public ?int $weightedMedianMinor,
        public ?int $q1Minor,
        public ?int $q3Minor,
        public ?int $madMinor,
        public ?int $dispersionBasisPoints,
        public ?int $confidenceBasisPoints,
        public ?PriceConfidenceLevel $confidenceLevel,
        public array $reasonCodes,
        public array $confidenceComponents,
        public array $inputSnapshot,
        public array $items,
    ) {
        if ($inputCount !== count($items)) {
            throw new InvalidArgumentException(
                'Price-estimate input totals must match persisted item evidence.',
            );
        }

        if ($includedCount + $outlierCount + $unresolvedCount !== $inputCount) {
            throw new InvalidArgumentException(
                'Every price-estimate input must have exactly one final decision.',
            );
        }

        if (
            $status !== PriceEstimateStatus::NeedsInput
            && (
                $estimateLowMinor === null
                || $estimateMinor === null
                || $estimateHighMinor === null
                || $confidenceBasisPoints === null
                || $confidenceLevel === null
            )
        ) {
            throw new InvalidArgumentException(
                'A completed price estimate requires values and confidence evidence.',
            );
        }
    }
}
