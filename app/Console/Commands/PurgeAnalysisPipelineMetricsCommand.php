<?php

namespace App\Console\Commands;

use App\Analysis\Metrics\AnalysisPipelineMetricsConfiguration;
use App\Analysis\Metrics\PurgeAnalysisPipelineMetrics;
use Illuminate\Console\Command;
use Throwable;

final class PurgeAnalysisPipelineMetricsCommand extends Command
{
    protected $signature = 'operations:purge-analysis-pipeline-metrics
        {--limit= : Maximum number of expired metric rows to remove}';

    protected $description = 'Purge a bounded batch of expired Analysis pipeline metrics';

    public function handle(
        AnalysisPipelineMetricsConfiguration $configuration,
        PurgeAnalysisPipelineMetrics $purge,
    ): int {
        try {
            $limit = $configuration->purgeLimit($this->option('limit'));
            $deleted = $purge->execute($limit);
        } catch (Throwable) {
            $this->components->error(
                'The pipeline metric retention configuration or purge failed.',
            );

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Purged %d expired Analysis pipeline metric rows.',
            $deleted,
        ));

        return self::SUCCESS;
    }
}
