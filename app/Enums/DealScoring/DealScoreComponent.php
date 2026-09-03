<?php

namespace App\Enums\DealScoring;

enum DealScoreComponent: string
{
    case EstimatedNetMargin = 'estimated_net_margin';
    case PriceConfidence = 'price_confidence';
    case ResaleDemand = 'resale_demand';
    case InverseRisk = 'inverse_risk';
    case LogisticsSimplicity = 'logistics_simplicity';
}
