<?php

namespace App\Enums\Pricing;

enum PriceEstimateStatus: string
{
    case Estimated = 'estimated';
    case LowConfidence = 'low_confidence';
    case NeedsInput = 'needs_input';
}
