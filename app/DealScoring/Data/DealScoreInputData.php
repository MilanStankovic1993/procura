<?php

namespace App\DealScoring\Data;

use App\Enums\Pricing\PriceConfidenceLevel;
use App\Enums\Risk\RiskLevel;

final readonly class DealScoreInputData
{
    /**
     * @param  array<string, array<string, mixed>>  $sourceSnapshots
     * @param  list<string>  $verificationActions
     */
    public function __construct(
        public ?int $profitMarginBasisPoints,
        public int $profitConfidenceBasisPoints,
        public ?int $priceConfidenceBasisPoints,
        public ?PriceConfidenceLevel $priceConfidenceLevel,
        public ?int $demandScore,
        public int $demandConfidenceBasisPoints,
        public ?int $riskScore,
        public ?RiskLevel $riskLevel,
        public int $riskConfidenceBasisPoints,
        public ?int $logisticsScore,
        public int $logisticsConfidenceBasisPoints,
        public bool $productModelKnown,
        public array $sourceSnapshots,
        public array $verificationActions,
    ) {}
}
