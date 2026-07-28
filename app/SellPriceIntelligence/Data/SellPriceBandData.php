<?php

namespace App\SellPriceIntelligence\Data;

use App\Enums\Pricing\PriceConfidenceLevel;
use App\Enums\Sell\SellPriceBandStatus;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class SellPriceBandData
{
    /**
     * @param  array<string, int>  $confidenceComponents
     * @param  list<string>  $reasonCodes
     * @param  list<string>  $unknownFacts
     * @param  list<string>  $verificationActions
     * @param  array<string, mixed>  $inputSnapshot
     * @param  list<array<string, mixed>>  $items
     */
    public function __construct(
        public SellPriceBandStatus $status,
        public string $algorithmVersion,
        public string $inputHash,
        public CarbonImmutable $calculatedAt,
        public string $targetCountryCode,
        public string $targetCurrencyCode,
        public int $inputCount,
        public int $includedCount,
        public int $outlierCount,
        public ?int $quickSaleLowMinor,
        public ?int $quickSaleHighMinor,
        public ?int $recommendedLowMinor,
        public ?int $recommendedHighMinor,
        public ?int $ambitiousLowMinor,
        public ?int $ambitiousHighMinor,
        public ?int $medianMinor,
        public ?int $weightedMedianMinor,
        public ?int $q1Minor,
        public ?int $q3Minor,
        public ?int $madMinor,
        public ?int $dispersionBasisPoints,
        public ?int $confidenceBasisPoints,
        public ?PriceConfidenceLevel $confidenceLevel,
        public int $completenessBasisPoints,
        public array $confidenceComponents,
        public array $reasonCodes,
        public array $unknownFacts,
        public array $verificationActions,
        public array $inputSnapshot,
        public array $items,
    ) {
        if (
            $inputCount !== count($items)
            || $includedCount + $outlierCount !== $inputCount
        ) {
            throw new InvalidArgumentException(
                'Every Sell price-band input must have one final decision.',
            );
        }

        if (
            $status !== SellPriceBandStatus::NeedsInput
            && (
                $quickSaleLowMinor === null
                || $quickSaleHighMinor === null
                || $recommendedLowMinor === null
                || $recommendedHighMinor === null
                || $ambitiousLowMinor === null
                || $ambitiousHighMinor === null
                || $confidenceBasisPoints === null
                || $confidenceLevel === null
            )
        ) {
            throw new InvalidArgumentException(
                'Completed Sell guidance requires all three bands and confidence.',
            );
        }
    }
}
