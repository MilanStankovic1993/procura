<?php

namespace App\Enums\Pricing;

enum ExchangeRateResolutionStatus: string
{
    case Resolved = 'resolved';
    case Missing = 'missing';
    case Stale = 'stale';
}
