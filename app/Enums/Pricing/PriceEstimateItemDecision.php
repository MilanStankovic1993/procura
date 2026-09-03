<?php

namespace App\Enums\Pricing;

enum PriceEstimateItemDecision: string
{
    case Included = 'included';
    case Outlier = 'outlier';
    case MissingRate = 'missing_rate';
    case StaleRate = 'stale_rate';
    case InvalidAmount = 'invalid_amount';
    case InvalidNormalization = 'invalid_normalization';
}
