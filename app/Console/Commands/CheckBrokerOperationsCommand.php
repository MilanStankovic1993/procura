<?php

namespace App\Console\Commands;

use App\BrokerRequests\Operations\BrokerOperationsMonitor;
use Illuminate\Console\Command;
use JsonException;

final class CheckBrokerOperationsCommand extends Command
{
    protected $signature = 'broker-operations:status
        {--fail-on-attention : Return a non-zero exit code when an attention signal is present}
        {--json : Emit one machine-readable JSON document}';

    protected $description = 'Inspect bounded broker lifecycle attention signals';

    /**
     * @throws JsonException
     */
    public function handle(BrokerOperationsMonitor $monitor): int
    {
        $report = $monitor->inspect();

        if ((bool) $this->option('json')) {
            $this->line(json_encode(
                $report->operatorPayload(),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));
        } else {
            $this->table(
                ['Signal', 'Count'],
                collect($report->counts)
                    ->map(
                        fn (int $count, string $signal): array => [
                            $signal,
                            $count,
                        ],
                    )
                    ->values()
                    ->all(),
            );

            if ($report->attentionCount() > 0) {
                $this->components->warn(
                    'Broker lifecycle attention is required.',
                );
            } else {
                $this->components->info(
                    'Broker lifecycle monitoring is clear.',
                );
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
