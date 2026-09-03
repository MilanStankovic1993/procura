<?php

namespace App\SellPriceIntelligence\Metrics;

use Illuminate\Support\Facades\DB;

final class PurgeSellPriceIntelligenceMetrics
{
    public function __construct(
        private readonly SellPriceIntelligenceMetricsConfiguration $configuration,
    ) {}

    public function execute(int $limit): int
    {
        $identifiers = DB::table('sell_price_intelligence_metrics')
            ->where(
                'recorded_at',
                '<',
                now()->subDays($this->configuration->retentionDays()),
            )
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->all();

        if ($identifiers === []) {
            return 0;
        }

        return DB::table('sell_price_intelligence_metrics')
            ->whereIn('id', $identifiers)
            ->delete();
    }
}
