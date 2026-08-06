<?php

namespace App\Console\Commands;

use App\SellPriceIntelligence\Metrics\PurgeSellPriceIntelligenceMetrics;
use App\SellPriceIntelligence\Metrics\SellPriceIntelligenceMetricsConfiguration;
use Illuminate\Console\Command;
use Throwable;

final class PurgeSellPriceIntelligenceMetricsCommand extends Command
{
    protected $signature = 'operations:purge-sell-price-intelligence-metrics
        {--limit= : Maximum number of expired metric rows to remove}';

    protected $description = 'Purge a bounded batch of expired Sell price-intelligence metrics';

    public function handle(
        SellPriceIntelligenceMetricsConfiguration $configuration,
        PurgeSellPriceIntelligenceMetrics $purge,
    ): int {
        try {
            $deleted = $purge->execute(
                $configuration->purgeLimit($this->option('limit')),
            );
        } catch (Throwable) {
            $this->components->error(
                'The Sell metric retention configuration or purge failed.',
            );

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Purged %d expired Sell price-intelligence metric rows.',
            $deleted,
        ));

        return self::SUCCESS;
    }
}
