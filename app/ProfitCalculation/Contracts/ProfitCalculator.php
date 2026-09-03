<?php

namespace App\ProfitCalculation\Contracts;

use App\Models\Analysis;
use App\Models\CostInput;
use App\Models\PriceEstimate;
use App\Models\RiskAssessment;
use App\ProfitCalculation\Data\ProfitEstimateData;

interface ProfitCalculator
{
    public function calculate(
        Analysis $analysis,
        PriceEstimate $priceEstimate,
        RiskAssessment $riskAssessment,
        CostInput $costInput,
    ): ProfitEstimateData;
}
