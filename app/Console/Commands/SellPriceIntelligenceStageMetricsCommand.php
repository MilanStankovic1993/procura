<?php

namespace App\Console\Commands;

use App\SellPriceIntelligence\Metrics\SellPriceIntelligenceMetricsConfiguration;
use App\SellPriceIntelligence\Metrics\SellPriceIntelligenceMetricsReporter;
use Illuminate\Console\Command;
use JsonException;
use Throwable;

final class SellPriceIntelligenceStageMetricsCommand extends Command
{
    protected $signature = 'operations:sell-price-intelligence-stage-metrics
        {--window= : Bounded lookback window in minutes}
        {--limit= : Maximum number of comparable recalculation rows}
        {--minimum-samples= : Minimum operations required for every stage}
        {--expected-operations= : Exact comparable recalculation count required for staging evidence}
        {--expected-scopes= : Exact total scope count required for staging evidence}
        {--allow-local-rehearsal : Permit a non-evidence local or testing report}
        {--json : Emit exactly one identifier-free JSON document}';

    protected $description = 'Aggregate bounded multi-scope Sell recalculation latency evidence';

    /** @throws JsonException */
    public function handle(
        SellPriceIntelligenceMetricsConfiguration $configuration,
        SellPriceIntelligenceMetricsReporter $reporter,
    ): int {
        if (app()->environment('production')) {
            return $this->failBeforeReport(
                'production_forbidden',
                'Sell release-evidence aggregation is forbidden in production.',
            );
        }

        if (
            ! app()->environment('staging')
            && ! (bool) $this->option('allow-local-rehearsal')
        ) {
            return $this->failBeforeReport(
                'staging_environment_required',
                'Use staging, or explicitly allow a non-evidence local rehearsal.',
            );
        }

        try {
            $configuration->assertValid();

            if (! $configuration->enabled()) {
                return $this->failBeforeReport(
                    'metrics_disabled',
                    'Sell price-intelligence metrics are disabled.',
                );
            }

            $limit = $configuration->sampleLimit($this->option('limit'));
            $minimumSamples = $configuration->minimumSamples(
                $this->option('minimum-samples'),
                $limit,
            );
            $expectedOperations = $configuration->expectedOperations(
                $this->option('expected-operations'),
                $limit,
                $minimumSamples,
                app()->environment('staging'),
            );
            $report = $reporter->report(
                $configuration->windowMinutes($this->option('window')),
                $limit,
                $minimumSamples,
                $expectedOperations,
                $configuration->expectedScopes(
                    $this->option('expected-scopes'),
                    $expectedOperations ?? $minimumSamples,
                    app()->environment('staging'),
                ),
            );
        } catch (Throwable) {
            return $this->failBeforeReport(
                'invalid_configuration',
                'The Sell metric configuration or report bounds are invalid.',
            );
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode(
                $report,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));
        } else {
            $this->table(
                ['Stage', 'Samples', 'p50 ms', 'p95 ms', 'p99 ms', 'Budget', 'Status'],
                collect($report['stages'])
                    ->map(static fn (array $stage, string $name): array => [
                        $name,
                        $stage['samples'],
                        $stage['p50_milliseconds'] ?? '-',
                        $stage['p95_milliseconds'] ?? '-',
                        $stage['p99_milliseconds'] ?? '-',
                        $stage['maximum_p95_milliseconds'],
                        $stage['status'],
                    ])
                    ->values()
                    ->all(),
            );
            $this->components->info(
                $report['status'] === 'passed'
                    ? 'Multi-scope Sell recalculation evidence passed.'
                    : 'The report is not eligible passing release evidence.',
            );
        }

        return app()->environment('staging')
            ? ($report['status'] === 'passed' ? self::SUCCESS : self::FAILURE)
            : self::SUCCESS;
    }

    /** @throws JsonException */
    private function failBeforeReport(string $code, string $message): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'status' => 'failed',
                'error_code' => $code,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->components->error($message);
        }

        return self::FAILURE;
    }
}
