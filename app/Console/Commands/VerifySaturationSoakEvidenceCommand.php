<?php

namespace App\Console\Commands;

use App\Operations\Capacity\SaturationSoakEvidenceConfiguration;
use App\Operations\Capacity\SaturationSoakEvidenceVerifier;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;

final class VerifySaturationSoakEvidenceCommand extends Command
{
    protected $signature = 'operations:verify-saturation-soak-evidence
        {--input= : JSON path relative to the private performance-evidence directory}
        {--expected-release= : Exact immutable release commit from the evidence ticket}
        {--allow-local-rehearsal : Permit non-production contract rehearsal outside staging}
        {--json : Emit exactly one machine-readable JSON document}';

    protected $description = 'Verify identifier-free staging saturation and soak telemetry';

    /**
     * @throws JsonException
     */
    public function handle(
        SaturationSoakEvidenceConfiguration $configuration,
        SaturationSoakEvidenceVerifier $verifier,
    ): int {
        if (app()->environment('production')) {
            return $this->failBeforeVerification(
                'production_forbidden',
                'Saturation and soak evidence verification is forbidden in production.',
            );
        }

        if (
            ! app()->environment('staging')
            && ! (bool) $this->option('allow-local-rehearsal')
        ) {
            return $this->failBeforeVerification(
                'staging_environment_required',
                'Release evidence must be verified in staging.',
            );
        }

        try {
            $configuration->inputContractVersion();
            $configuration->reportContractVersion();
            $configuration->phaseMinimumDurations();
            $configuration->budgets();
            $configuration->privateDirectory();
            $configuration->maximumFileBytes();
            $configuration->maximumSamples();
            $configuration->minimumSampleIntervalSeconds();
            $configuration->maximumSampleIntervalSeconds();
            $configuration->maximumGapMultiplier();
        } catch (RuntimeException) {
            return $this->failBeforeVerification(
                'invalid_configuration',
                'The saturation and soak evidence configuration is invalid.',
            );
        }

        try {
            $expectedRelease = $configuration->releaseSha(
                $this->option('expected-release'),
            );
            $path = $configuration->inputPath($this->option('input'));
            $contents = file_get_contents($path);

            if ($contents === false) {
                throw new RuntimeException(
                    'The saturation evidence input file could not be read.',
                );
            }

            $payload = json_decode(
                $contents,
                true,
                64,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException|RuntimeException) {
            return $this->failBeforeVerification(
                'invalid_input',
                'The private saturation evidence input is invalid.',
            );
        }

        try {
            $report = $verifier->verify($payload, $expectedRelease);
        } catch (RuntimeException) {
            return $this->failBeforeVerification(
                'invalid_evidence',
                'The saturation evidence contract is invalid.',
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
                ['Release', 'Status', 'Samples', 'Release evidence'],
                [[
                    $report->releaseSha,
                    $payload['status'],
                    $report->sampleCount,
                    $report->releaseEvidence() ? 'yes' : 'no',
                ]],
            );

            if ($report->passed()) {
                $this->components->info(
                    $report->releaseEvidence()
                        ? 'Saturation and soak release evidence passed.'
                        : 'The local evidence contract rehearsal passed.',
                );
            } else {
                $this->components->error(
                    'Saturation and soak evidence failed: '
                    .implode(', ', $report->failedChecks),
                );
            }
        }

        return $report->passed() ? self::SUCCESS : self::FAILURE;
    }

    private function failBeforeVerification(
        string $code,
        string $message,
    ): int {
        if ((bool) $this->option('json')) {
            $this->line(json_encode(
                [
                    'status' => 'failed',
                    'error_code' => $code,
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));
        } else {
            $this->components->error($message);
        }

        return self::FAILURE;
    }
}
