<?php

namespace App\Console\Commands;

use App\Analysis\Contracts\ConfiguredListingAiAnalyzer;
use App\Analysis\Contracts\ListingAiAnalyzer;
use App\Analysis\Evaluation\AnalysisProviderEvaluationConfiguration;
use App\Analysis\Evaluation\AnalysisProviderEvaluator;
use App\Analysis\Evaluation\GoldenAnalysisDataset;
use App\Analysis\Providers\FakeListingAiAnalyzer;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;
use Throwable;

final class EvaluateAnalysisProviderCommand extends Command
{
    protected $signature = 'analyses:evaluate-provider
        {--expected-release= : Exact immutable release commit under evaluation}
        {--expected-provider= : Exact configured external provider expected in staging}
        {--expected-model= : Exact configured external model expected in staging}
        {--confirm-synthetic-provider-calls : Confirm bounded external calls over repository fixtures}
        {--allow-local-rehearsal : Permit a fake-provider rehearsal outside staging}
        {--json : Emit exactly one aggregate JSON document}';

    protected $description = 'Evaluate listing extraction against sealed synthetic golden cases';

    /** @throws JsonException */
    public function handle(
        AnalysisProviderEvaluationConfiguration $configuration,
        AnalysisProviderEvaluator $evaluator,
        ListingAiAnalyzer $analyzer,
    ): int {
        if (app()->environment('production')) {
            return $this->failBeforeEvaluation(
                'production_forbidden',
                'Analysis provider evaluation is forbidden in production.',
            );
        }

        $staging = app()->environment('staging');

        if (! $staging && ! (bool) $this->option('allow-local-rehearsal')) {
            return $this->failBeforeEvaluation(
                'staging_environment_required',
                'Use staging, or explicitly allow a fake-provider rehearsal.',
            );
        }

        try {
            $configuration->assertValid();
            $releaseSha = $configuration->releaseSha(
                $this->option('expected-release'),
            );
        } catch (RuntimeException) {
            return $this->failBeforeEvaluation(
                'invalid_configuration',
                'The evaluation configuration or release binding is invalid.',
            );
        }

        if ($staging) {
            $context = $this->stagingContext($configuration, $analyzer);

            if ($context === null) {
                return self::FAILURE;
            }
        } else {
            if (
                config('analyses.provider') !== 'fake'
                || ! $analyzer instanceof FakeListingAiAnalyzer
            ) {
                return $this->failBeforeEvaluation(
                    'fake_provider_required',
                    'Local rehearsal may only use the deterministic fake provider.',
                );
            }

            $context = [
                'provider' => 'fake',
                'model' => (string) config('analyses.fake_model'),
                'release_evidence' => false,
            ];
        }

        try {
            $dataset = GoldenAnalysisDataset::load($configuration);
            $report = $evaluator->evaluate(
                dataset: $dataset,
                analyzer: $analyzer,
                provider: $context['provider'],
                model: $context['model'],
                releaseSha: $releaseSha,
                eligibleForReleaseEvidence: $context['release_evidence'],
            );
        } catch (Throwable) {
            return $this->failBeforeEvaluation(
                'evaluation_failed',
                'The sealed dataset or provider evaluation failed.',
            );
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode(
                $report->toArray(),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));
        } else {
            $payload = $report->toArray();
            $this->table(
                ['Provider', 'Model', 'Cases', 'Passed', 'Status', 'Release evidence'],
                [[
                    $report->provider,
                    $report->model,
                    $report->metrics['case_count'],
                    $report->metrics['passed_case_count'],
                    $payload['status'],
                    $report->releaseEvidence() ? 'yes' : 'no',
                ]],
            );
        }

        return $report->passed() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{provider: string, model: string, release_evidence: bool}|null
     */
    private function stagingContext(
        AnalysisProviderEvaluationConfiguration $configuration,
        ListingAiAnalyzer $analyzer,
    ): ?array {
        if (
            ! $configuration->externalCallsEnabled()
            || ! (bool) $this->option('confirm-synthetic-provider-calls')
            || config('analyses.submission_enabled') === true
        ) {
            $this->failBeforeEvaluation(
                'external_calls_not_authorized',
                'Staging evaluation calls are not explicitly and safely authorized.',
            );

            return null;
        }

        if (
            ! $analyzer instanceof ConfiguredListingAiAnalyzer
            || ! $analyzer->isConfigured()
            || $analyzer->provider() === 'fake'
        ) {
            $this->failBeforeEvaluation(
                'external_provider_required',
                'Staging release evidence requires a configured external provider.',
            );

            return null;
        }

        try {
            $expectedProvider = $configuration->providerOrModel(
                $this->option('expected-provider'),
                'provider',
            );
            $expectedModel = $configuration->providerOrModel(
                $this->option('expected-model'),
                'model',
            );
        } catch (RuntimeException) {
            $this->failBeforeEvaluation(
                'invalid_provider_binding',
                'The expected provider or model binding is invalid.',
            );

            return null;
        }

        if (
            $expectedProvider !== $analyzer->provider()
            || $expectedModel !== $analyzer->model()
        ) {
            $this->failBeforeEvaluation(
                'provider_binding_mismatch',
                'The configured provider or model does not match the evidence ticket.',
            );

            return null;
        }

        return [
            'provider' => $expectedProvider,
            'model' => $expectedModel,
            'release_evidence' => true,
        ];
    }

    private function failBeforeEvaluation(string $code, string $message): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode(
                ['status' => 'failed', 'error_code' => $code],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));
        } else {
            $this->components->error($message);
        }

        return self::FAILURE;
    }
}
