<?php

namespace App\ProfitCalculation\Data;

use App\Enums\Profit\ProfitConfidenceLevel;
use App\Enums\Profit\ProfitEstimateStatus;
use Carbon\CarbonImmutable;

final readonly class ProfitEstimateData
{
    /**
     * @param  list<string>  $reasonCodes
     * @param  array<string, int>  $confidenceComponents
     * @param  array<string, mixed>  $inputSnapshot
     * @param  list<array<string, mixed>>  $items
     */
    public function __construct(
        public ProfitEstimateStatus $status,
        public string $calculationVersion,
        public string $inputHash,
        public CarbonImmutable $calculationAt,
        public string $currencyCode,
        public int $expectedSalePriceMinor,
        public ?int $purchasePriceMinor,
        public ?int $grossMarginMinor,
        public int $knownCostsMinor,
        public ?int $additionalCostsMinor,
        public ?int $totalCostMinor,
        public ?int $expectedNetProfitMinor,
        public ?int $profitMarginBasisPoints,
        public ?int $returnOnInvestedCapitalBasisPoints,
        public int $confidenceBasisPoints,
        public ProfitConfidenceLevel $confidenceLevel,
        public int $unknownCount,
        public array $reasonCodes,
        public array $confidenceComponents,
        public array $inputSnapshot,
        public array $items,
    ) {}
}
