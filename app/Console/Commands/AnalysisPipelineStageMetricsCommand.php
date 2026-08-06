<?php

namespace App\Console\Commands;

use App\Analysis\Metrics\AnalysisPipelineMetricsConfiguration;
use App\Analysis\Metrics\AnalysisPipelineMetricsReporter;
use Illuminate\Console\Command;
use JsonException;
use Throwable;

final class AnalysisPipelineStageMetricsCommand extends Command
{
    protected $signature = 'operations:analysis-pipeline-stage-metrics
        {--window= : Bounded lookback window in minutes}
        {--limit= : Maximum number of metric rows}
        {--minimum-samples= : Minimum samples required for every stage}
        {--expected-samples= : Exact attempt count required for staging evidence}
        {--allow-local-rehearsal : Permit a non-evidence local or testing report}
        {--json : Emit exactly one identifier-free JSON document}';

    protected $description = 'Aggregate bounded Analysis pipeline stage latency evidence';

    /** @throws JsonException */
    public function handle(
        AnalysisPipelineMetricsConfiguration $configuration,
        AnalysisPipelineMetricsReporter $reporter,
    ): int {
        if (app()->environment('production')) {
            return $this->failBeforeReport(
                'production_forbidden',
                'Pipeline release-evidence aggregation is forbidden in production.',
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
                    'Analysis pipeline metrics are disabled.',
                );
            }

            $limit = $configuration->sampleLimit($this->option('limit'));
            $minimumSamples = $configuration->minimumSamples(
                $this->option('minimum-samples'),
                $limit,
            );
            $report = $reporter->report(
                $configuration->windowMinutes($this->option('window')),
                $limit,
                $minimumSamples,
                $configuration->expectedSamples(
                    $this->option('expected-samples'),
                    $limit,
                    $minimumSamples,
                    app()->environment('staging'),
                ),
            );
        } catch (Throwable) {
            return $this->failBeforeReport(
                'invalid_configuration',
                'The pipeline metric configuration or report bounds are invalid.',
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
                    ? 'Pipeline stage evidence passed.'
                    : 'The report is not eligible passing release evidence.',
            );
        }

        if (app()->environment('staging')) {
            return $report['status'] === 'passed'
                ? self::SUCCESS
                : self::FAILURE;
        }

        return self::SUCCESS;
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
