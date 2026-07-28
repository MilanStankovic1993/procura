<?php

namespace App\Enums\Sell;

enum SellPriceBandItemDecision: string
{
    case Included = 'included';
    case Outlier = 'outlier';
}
