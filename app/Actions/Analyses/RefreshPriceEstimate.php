<?php

namespace App\Actions\Analyses;

use App\Models\Analysis;
use App\Models\ComparableSet;
use App\Models\PriceEstimate;
use App\Pricing\Contracts\PriceEstimator;

class RefreshPriceEstimate
{
    public function __construct(
        private readonly PriceEstimator $estimator,
        private readonly RecordPriceEstimate $estimates,
        private readonly PriceEstimateResultProjection $projection,
    ) {}

    /**
     * @param  array<string, mixed>  $targetFacts
     */
    public function refresh(
        Analysis $analysis,
        ComparableSet $comparableSet,
        array $targetFacts = [],
    ): PriceEstimate {
        $result = $this->estimator->estimate(
            $analysis,
            $comparableSet,
            $targetFacts,
        );
        $estimate = $this->estimates->record(
            $analysis->getKey(),
            $comparableSet->getKey(),
            $result,
        );
        $this->projection->apply($analysis, $estimate);

        return $estimate;
    }
}
