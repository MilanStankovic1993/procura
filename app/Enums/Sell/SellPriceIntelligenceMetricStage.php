<?php

namespace App\Enums\Sell;

enum SellPriceIntelligenceMetricStage: string
{
    case ScopeDiscovery = 'scope_discovery';
    case ComparableSelection = 'comparable_selection';
    case SelectionPersistence = 'selection_persistence';
    case PriceBandEstimation = 'price_band_estimation';
    case PriceBandPersistence = 'price_band_persistence';

    public function durationColumn(): string
    {
        return $this->value.'_microseconds';
    }
}
