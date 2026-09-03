<?php

namespace App\Enums\Comparables;

enum MarketCompatibilityStatus: string
{
    case Compatible = 'compatible';
    case Incompatible = 'incompatible';
}
