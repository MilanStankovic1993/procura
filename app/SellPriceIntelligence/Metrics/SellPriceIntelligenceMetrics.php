<?php

namespace App\SellPriceIntelligence\Metrics;

use App\SellPriceIntelligence\Metrics\Contracts\SellPriceIntelligenceMetricRecorder;
use Illuminate\Support\Facades\DB;
use Throwable;

final class SellPriceIntelligenceMetrics
{
    public function __construct(
        private readonly SellPriceIntelligenceMetricRecorder $recorder,
    ) {}

    public function recordAfterCommit(
        SellPriceIntelligenceMetricTimer $timer,
    ): void {
        if (config('performance.sell_price_intelligence_metrics.enabled') !== true) {
            return;
        }

        DB::afterCommit(function () use ($timer): void {
            try {
                $this->recorder->record($timer);
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }
}
