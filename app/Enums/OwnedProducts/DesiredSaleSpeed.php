<?php

namespace App\Enums\OwnedProducts;

enum DesiredSaleSpeed: string
{
    case Unknown = 'unknown';
    case Fast = 'fast';
    case Balanced = 'balanced';
    case MaximumValue = 'maximum_value';
}
