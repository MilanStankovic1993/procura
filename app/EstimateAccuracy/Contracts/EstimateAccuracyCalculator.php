<?php

namespace App\EstimateAccuracy\Contracts;

use App\EstimateAccuracy\Data\EstimateAccuracyReportData;
use App\Models\ProfitEstimate;
use App\Models\RealizedProfit;

interface EstimateAccuracyCalculator
{
    public function calculate(
        ProfitEstimate $estimate,
        RealizedProfit $realizedProfit,
    ): EstimateAccuracyReportData;
}
