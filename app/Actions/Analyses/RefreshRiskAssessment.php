<?php

namespace App\Actions\Analyses;

use App\Models\Analysis;
use App\Models\ComparableSet;
use App\Models\PriceEstimate;
use App\Models\ProductMatch;
use App\Models\RiskAssessment;
use App\RiskAssessment\Contracts\RiskEvaluator;

class RefreshRiskAssessment
{
    public function __construct(
        private readonly RiskEvaluator $evaluator,
        private readonly RecordRiskAssessment $assessments,
        private readonly RiskAssessmentResultProjection $projection,
    ) {}

    /** @param array<string, mixed> $targetFacts */
    public function refresh(
        Analysis $analysis,
        ProductMatch $productMatch,
        ComparableSet $comparableSet,
        PriceEstimate $priceEstimate,
        array $targetFacts = [],
    ): RiskAssessment {
        $result = $this->evaluator->evaluate(
            $analysis,
            $productMatch,
            $comparableSet,
            $priceEstimate,
            $targetFacts,
        );
        $assessment = $this->assessments->record(
            $analysis->getKey(),
            $productMatch->getKey(),
            $comparableSet->getKey(),
            $priceEstimate->getKey(),
            $result,
        );
        $this->projection->apply($analysis, $assessment);

        return $assessment;
    }
}
