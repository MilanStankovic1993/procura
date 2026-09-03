<?php

namespace App\Enums\Pricing;

enum ExchangeRateDirection: string
{
    case Unresolved = 'unresolved';
    case Identity = 'identity';
    case Direct = 'direct';
    case Inverse = 'inverse';
}
