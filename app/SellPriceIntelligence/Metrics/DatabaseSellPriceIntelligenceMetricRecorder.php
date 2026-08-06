<?php

namespace App\SellPriceIntelligence\Metrics;

use App\Models\SellPriceIntelligenceMetric;
use App\SellPriceIntelligence\Metrics\Contracts\SellPriceIntelligenceMetricRecorder;

final class DatabaseSellPriceIntelligenceMetricRecorder implements SellPriceIntelligenceMetricRecorder
{
    public function record(SellPriceIntelligenceMetricTimer $timer): void
    {
        if (config('performance.sell_price_intelligence_metrics.enabled') !== true) {
            return;
        }

        SellPriceIntelligenceMetric::query()->create([
            'operation' => $timer->operation,
            'metrics_version' => config(
                'performance.sell_price_intelligence_metrics.metrics_version',
            ),
            'selector_version' => config(
                'sell_price_intelligence.selector_version',
            ),
            'algorithm_version' => config(
                'sell_price_intelligence.algorithm_version',
            ),
            ...$timer->countColumns(),
            ...$timer->durationColumns(),
            'total_microseconds' => $timer->totalMicroseconds(),
            'recorded_at' => now(),
        ]);
    }
}
