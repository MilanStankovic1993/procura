<?php

namespace App\Enums\Sell;

enum SellPriceBandStatus: string
{
    case Ready = 'ready';
    case LowConfidence = 'low_confidence';
    case NeedsInput = 'needs_input';
}
