<?php

namespace App\SellPriceIntelligence\Metrics\Contracts;

use App\SellPriceIntelligence\Metrics\SellPriceIntelligenceMetricTimer;

interface SellPriceIntelligenceMetricRecorder
{
    public function record(SellPriceIntelligenceMetricTimer $timer): void;
}
