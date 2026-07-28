<?php

namespace App\Enums\Profit;

enum ProfitEstimateStatus: string
{
    case Estimated = 'estimated';
    case LowConfidence = 'low_confidence';
    case NeedsInput = 'needs_input';
}
