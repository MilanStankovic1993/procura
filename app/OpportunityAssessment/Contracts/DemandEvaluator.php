<?php

namespace App\OpportunityAssessment\Contracts;

use App\Models\Analysis;
use App\Models\OpportunityInput;
use App\Models\ProfitEstimate;
use App\OpportunityAssessment\Data\OpportunityAssessmentData;

interface DemandEvaluator
{
    public function evaluate(
        Analysis $analysis,
        ProfitEstimate $profitEstimate,
        OpportunityInput $input,
    ): OpportunityAssessmentData;
}
