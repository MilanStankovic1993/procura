<?php

namespace App\Pricing\Contracts;

use App\Models\Analysis;
use App\Models\ComparableSet;
use App\Pricing\Data\PriceEstimateData;

interface PriceEstimator
{
    /**
     * @param  array<string, mixed>  $targetFacts
     */
    public function estimate(
        Analysis $analysis,
        ComparableSet $comparableSet,
        array $targetFacts = [],
    ): PriceEstimateData;
}
