<?php

namespace App\RiskAssessment\Contracts;

use App\Models\Analysis;
use App\Models\ComparableSet;
use App\Models\PriceEstimate;
use App\Models\ProductMatch;
use App\RiskAssessment\Data\RiskAssessmentData;

interface RiskEvaluator
{
    /** @param array<string, mixed> $targetFacts */
    public function evaluate(
        Analysis $analysis,
        ProductMatch $productMatch,
        ComparableSet $comparableSet,
        PriceEstimate $priceEstimate,
        array $targetFacts = [],
    ): RiskAssessmentData;
}
