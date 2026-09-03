<?php

namespace App\Console\Commands;

use App\Analysis\Monitoring\AnalysisProviderMonitor;
use App\Analysis\Monitoring\AnalysisProviderMonitoringConfiguration;
use Illuminate\Console\Command;
use JsonException;

final class CheckAnalysisProviderCommand extends Command
{
    protected $signature = 'analyses:provider-status
        {--fail-on-attention : Return a non-zero exit code when monitoring is disabled or attention is required}
        {--json : Emit one machine-readable JSON document}';

    protected $description = 'Inspect bounded AI provider cost and reliability signals';

    /** @throws JsonException */
    public function handle(
        AnalysisProviderMonitor $monitor,
        AnalysisProviderMonitoringConfiguration $configuration,
    ): int {
        if (! $configuration->enabled()) {
            if ((bool) $this->option('json')) {
                $this->line(json_encode(
                    ['status' => 'not_monitored'],
                    JSON_THROW_ON_ERROR,
                ));
            } else {
                $this->components->warn('AI provider monitoring is disabled.');
            }

            return (bool) $this->option('fail-on-attention')
                ? self::FAILURE
                : self::SUCCESS;
        }

        $report = $monitor->inspect();

        if ((bool) $this->option('json')) {
            $this->line(json_encode(
                $report->operatorPayload(),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));
        } else {
            $signals = $report->attentionSignals();
            $this->table(
                ['Signal', 'Count', 'Attention'],
                collect($report->counts)
                    ->map(
                        fn (int $count, string $signal): array => [
                            $signal,
                            $count,
                            $signals[$signal] ? 'yes' : 'no',
                        ],
                    )
                    ->values()
                    ->all(),
            );

            if ($report->attentionCount() > 0) {
                $this->components->warn('AI provider attention is required.');
            } else {
                $this->components->info('AI provider monitoring is clear.');
            }
        }

        if (
            (bool) $this->option('fail-on-attention')
            && $report->attentionCount() > 0
        ) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
