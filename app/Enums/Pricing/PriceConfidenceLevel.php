<?php

namespace App\Enums\Pricing;

enum PriceConfidenceLevel: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
