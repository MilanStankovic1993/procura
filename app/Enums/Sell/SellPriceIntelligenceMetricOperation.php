<?php

namespace App\Enums\Sell;

enum SellPriceIntelligenceMetricOperation: string
{
    case ComparableRecalculation = 'comparable_recalculation';
    case NormalizationRecalculation = 'normalization_recalculation';
}
