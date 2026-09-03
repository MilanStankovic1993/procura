<?php

namespace App\Enums\OwnedProducts;

enum CrossBorderPreference: string
{
    case Unknown = 'unknown';
    case DomesticOnly = 'domestic_only';
    case Allowed = 'cross_border_allowed';
    case Preferred = 'cross_border_preferred';
}
