<?php

namespace App\EstimateAccuracy\Data;

use App\Enums\EstimateAccuracy\EstimateAccuracyStatus;
use App\Pricing\Data\ExchangeRateResolutionData;
use Carbon\CarbonImmutable;

final readonly class EstimateAccuracyReportData
{
    /**
     * @param  array<string, array<string, int|null>>  $metrics
     * @param  list<string>  $reasonCodes
     * @param  list<string>  $unavailableMetrics
     * @param  array<string, mixed>  $inputSnapshot
     */
    public function __construct(
        public EstimateAccuracyStatus $status,
        public string $calculationVersion,
        public ExchangeRateResolutionData $conversion,
        public array $metrics,
        public array $reasonCodes,
        public array $unavailableMetrics,
        public array $inputSnapshot,
        public string $inputHash,
        public CarbonImmutable $calculatedAt,
    ) {}
}
