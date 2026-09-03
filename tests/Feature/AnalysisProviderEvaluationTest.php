<?php

use App\Analysis\Contracts\ConfiguredListingAiAnalyzer;
use App\Analysis\Contracts\ListingAiAnalyzer;
use App\Analysis\Data\AiAnalysisData;
use App\Analysis\Data\AnalysisInputData;
use App\Analysis\Evaluation\AnalysisProviderEvaluationConfiguration;
use App\Analysis\Evaluation\AnalysisProviderEvaluator;
use App\Analysis\Evaluation\GoldenAnalysisDataset;
use App\Analysis\Providers\FakeListingAiAnalyzer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

const GOLDEN_EVALUATION_RELEASE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
function runWithEvaluationEnvironment(string $environment, Closure $run): mixed
{
    $application = app();
    $original = $application->environment();
    $application->detectEnvironment(fn (): string => $environment);

    try {
        return $run();
    } finally {
        $application->detectEnvironment(fn (): string => $original);
    }
}

/** @return array<string, mixed> */
function goldenEvaluationPayload(): array
{
    return json_decode(
        file_get_contents(base_path(
            'resources/analysis/golden-listing-analysis-v1.json',
        )),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
}

test('the sealed synthetic dataset passes the deterministic rehearsal', function () {
    $configuration = app(AnalysisProviderEvaluationConfiguration::class);
    $dataset = GoldenAnalysisDataset::load($configuration);
    $report = app(AnalysisProviderEvaluator::class)->evaluate(
        dataset: $dataset,
        analyzer: new FakeListingAiAnalyzer,
        provider: 'fake',
        model: 'deterministic-fixture-v1',
        releaseSha: GOLDEN_EVALUATION_RELEASE,
        eligibleForReleaseEvidence: false,
    );
    $payload = $report->toArray();

    expect($report->passed())->toBeTrue()
        ->and($report->releaseEvidence())->toBeFalse()
        ->and($payload['report_contract_version'])
        ->toBe('analysis-provider-evaluation-report:v1')
        ->and($payload['dataset_version'])
        ->toBe('listing-extraction-golden:v1')
        ->and($payload['dataset_sha256'])->toMatch('/\A[0-9a-f]{64}\z/')
        ->and($payload['metrics']['case_count'])->toBe(8)
        ->and($payload['metrics']['passed_case_count'])->toBe(8)
        ->and($payload['metrics']['case_pass_rate_basis_points'])->toBe(10_000)
        ->and($payload['metrics']['actual_cost_minor'])->toBe(0)
        ->and($payload['failed_checks'])->toBe([]);

    expect(json_encode($payload, JSON_THROW_ON_ERROR))
        ->not->toContain('Bosch GSR')
        ->not->toContain('ignore prior rules')
        ->not->toContain('english_complete');
});

test('local command requires an explicit rehearsal and is never release evidence', function () {
    $exitCode = Artisan::call('analyses:evaluate-provider', [
        '--expected-release' => GOLDEN_EVALUATION_RELEASE,
        '--json' => true,
    ]);

    expect($exitCode)->toBe(1)
        ->and(json_decode(
            trim(Artisan::output()),
            true,
            flags: JSON_THROW_ON_ERROR,
        ))->toBe([
            'status' => 'failed',
            'error_code' => 'staging_environment_required',
        ]);

    $exitCode = Artisan::call('analyses:evaluate-provider', [
        '--expected-release' => GOLDEN_EVALUATION_RELEASE,
        '--allow-local-rehearsal' => true,
        '--json' => true,
    ]);
    $payload = json_decode(
        trim(Artisan::output()),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($exitCode)->toBe(0)
        ->and($payload['status'])->toBe('passed')
        ->and($payload['release_evidence'])->toBeFalse()
        ->and($payload['provider'])->toBe('fake')
        ->and($payload['model'])->toBe('deterministic-fixture-v1');
});

test('production evaluation is permanently forbidden', function () {
    $result = runWithEvaluationEnvironment('production', function (): array {
        $exitCode = Artisan::call('analyses:evaluate-provider', [
            '--expected-release' => GOLDEN_EVALUATION_RELEASE,
            '--allow-local-rehearsal' => true,
            '--confirm-synthetic-provider-calls' => true,
            '--json' => true,
        ]);

        return [$exitCode, json_decode(
            trim(Artisan::output()),
            true,
            flags: JSON_THROW_ON_ERROR,
        )];
    });

    expect($result)->toBe([1, [
        'status' => 'failed',
        'error_code' => 'production_forbidden',
    ]]);
});

test('local rehearsal cannot use an external provider', function () {
    config()->set('analyses.provider', 'gemini');
    Http::preventStrayRequests();

    $exitCode = Artisan::call('analyses:evaluate-provider', [
        '--expected-release' => GOLDEN_EVALUATION_RELEASE,
        '--allow-local-rehearsal' => true,
        '--json' => true,
    ]);

    expect($exitCode)->toBe(1)
        ->and(json_decode(
            trim(Artisan::output()),
            true,
            flags: JSON_THROW_ON_ERROR,
        ))->toBe([
            'status' => 'failed',
            'error_code' => 'fake_provider_required',
        ]);
    Http::assertNothingSent();
});

test('staging external calls require every independent safety gate', function () {
    config([
        'analyses.provider' => 'gemini',
        'analyses.providers.gemini.api_key' => 'synthetic-test-key',
        'analyses.submission_enabled' => false,
        'analyses.provider_evaluation.external_calls_enabled' => false,
    ]);
    Http::preventStrayRequests();

    $result = runWithEvaluationEnvironment('staging', function (): array {
        $exitCode = Artisan::call('analyses:evaluate-provider', [
            '--expected-release' => GOLDEN_EVALUATION_RELEASE,
            '--expected-provider' => 'gemini',
            '--expected-model' => 'gemini-3.7-flash',
            '--confirm-synthetic-provider-calls' => true,
            '--json' => true,
        ]);

        return [$exitCode, json_decode(
            trim(Artisan::output()),
            true,
            flags: JSON_THROW_ON_ERROR,
        )];
    });

    expect($result)->toBe([1, [
        'status' => 'failed',
        'error_code' => 'external_calls_not_authorized',
    ]]);

    config([
        'analyses.provider_evaluation.external_calls_enabled' => true,
        'analyses.submission_enabled' => true,
    ]);
    $submissionResult = runWithEvaluationEnvironment(
        'staging',
        function (): array {
            $exitCode = Artisan::call('analyses:evaluate-provider', [
                '--expected-release' => GOLDEN_EVALUATION_RELEASE,
                '--expected-provider' => 'gemini',
                '--expected-model' => 'gemini-3.7-flash',
                '--confirm-synthetic-provider-calls' => true,
                '--json' => true,
            ]);

            return [$exitCode, json_decode(
                trim(Artisan::output()),
                true,
                flags: JSON_THROW_ON_ERROR,
            )];
        },
    );

    expect($submissionResult)->toBe([1, [
        'status' => 'failed',
        'error_code' => 'external_calls_not_authorized',
    ]]);
    Http::assertNothingSent();
});

test('staging binds evidence to the exact configured provider and model', function () {
    config([
        'analyses.provider' => 'gemini',
        'analyses.providers.gemini.api_key' => 'synthetic-test-key',
        'analyses.submission_enabled' => false,
        'analyses.provider_evaluation.external_calls_enabled' => true,
    ]);
    Http::preventStrayRequests();

    $result = runWithEvaluationEnvironment('staging', function (): array {
        $exitCode = Artisan::call('analyses:evaluate-provider', [
            '--expected-release' => GOLDEN_EVALUATION_RELEASE,
            '--expected-provider' => 'gemini',
            '--expected-model' => 'wrong-model',
            '--confirm-synthetic-provider-calls' => true,
            '--json' => true,
        ]);

        return [$exitCode, json_decode(
            trim(Artisan::output()),
            true,
            flags: JSON_THROW_ON_ERROR,
        )];
    });

    expect($result)->toBe([1, [
        'status' => 'failed',
        'error_code' => 'provider_binding_mismatch',
    ]]);
    Http::assertNothingSent();
});

test('a simulated staging provider run emits eligible aggregate evidence', function () {
    config([
        'analyses.provider' => 'gemini',
        'analyses.providers.gemini.api_key' => 'synthetic-test-key',
        'analyses.providers.gemini.input_price_usd_per_million' => '0',
        'analyses.providers.gemini.output_price_usd_per_million' => '0',
        'analyses.submission_enabled' => false,
        'analyses.provider_evaluation.external_calls_enabled' => true,
    ]);
    Http::fake(function (Request $request) {
        $input = json_decode(
            (string) data_get($request->data(), 'contents.0.parts.0.text'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        return Http::response([
            'candidates' => [[
                'finishReason' => 'STOP',
                'content' => [
                    'parts' => [[
                        'text' => json_encode([
                            'normalized_title' => trim(
                                $input['listing']['title'],
                            ),
                            'normalized_description' => (
                                $input['listing']['description'] === null
                                    ? null
                                    : trim($input['listing']['description'])
                            ),
                            'confidence_basis_points' => 7500,
                        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    ]],
                ],
            ]],
            'usageMetadata' => [
                'promptTokenCount' => 100,
                'candidatesTokenCount' => 25,
            ],
        ]);
    });

    $result = runWithEvaluationEnvironment('staging', function (): array {
        $exitCode = Artisan::call('analyses:evaluate-provider', [
            '--expected-release' => GOLDEN_EVALUATION_RELEASE,
            '--expected-provider' => 'gemini',
            '--expected-model' => 'gemini-3.7-flash',
            '--confirm-synthetic-provider-calls' => true,
            '--json' => true,
        ]);

        return [$exitCode, json_decode(
            trim(Artisan::output()),
            true,
            flags: JSON_THROW_ON_ERROR,
        )];
    });

    expect($result[0])->toBe(0)
        ->and($result[1]['status'])->toBe('passed')
        ->and($result[1]['release_evidence'])->toBeTrue()
        ->and($result[1]['environment'])->toBe('staging')
        ->and($result[1]['provider'])->toBe('gemini')
        ->and($result[1]['model'])->toBe('gemini-3.7-flash')
        ->and($result[1]['metrics']['case_count'])->toBe(8)
        ->and($result[1]['metrics']['tokens_in'])->toBe(800)
        ->and($result[1]['metrics']['tokens_out'])->toBe(200);
    Http::assertSentCount(8);
});

test('evaluation failures expose only aggregate metric names', function () {
    $analyzer = new class implements ListingAiAnalyzer
    {
        public function analyze(AnalysisInputData $input): AiAnalysisData
        {
            return new AiAnalysisData(
                normalizedListing: [
                    'title' => 'Incorrect synthetic output',
                    'description' => $input->requestPayload['listing']['description'],
                    'marketplace_name' => $input->requestPayload['listing']['marketplace_name'],
                    'asking_price_minor' => $input->requestPayload['listing']['asking_price_minor'],
                    'currency_code' => $input->requestPayload['listing']['currency_code'],
                    'source_country_code' => $input->requestPayload['market_scope']['source_country_code'],
                    'target_country_code' => $input->requestPayload['market_scope']['target_country_code'],
                    'evidence_count' => count($input->requestPayload['evidence']),
                ],
                confidenceBasisPoints: 7500,
            );
        }
    };
    $report = app(AnalysisProviderEvaluator::class)->evaluate(
        GoldenAnalysisDataset::load(
            app(AnalysisProviderEvaluationConfiguration::class),
        ),
        $analyzer,
        'synthetic-failure',
        'synthetic-model',
        GOLDEN_EVALUATION_RELEASE,
        false,
    );
    $encoded = json_encode($report->toArray(), JSON_THROW_ON_ERROR);

    expect($report->passed())->toBeFalse()
        ->and($report->failedChecks)->toContain('title_exact_rate')
        ->and($report->failedChecks)->toContain('case_pass_rate')
        ->and($encoded)->not->toContain('english_complete')
        ->not->toContain('Bosch GSR');
});

test('the total reservation cap is checked before any provider call', function () {
    config()->set('analyses.provider_evaluation.maximum_total_cost_minor', 7);
    $tracker = (object) ['calls' => 0];
    $analyzer = new class($tracker) implements ConfiguredListingAiAnalyzer
    {
        public function __construct(public object $tracker) {}

        public function isConfigured(): bool
        {
            return true;
        }

        public function provider(): string
        {
            return 'bounded-test';
        }

        public function model(): string
        {
            return 'bounded-model';
        }

        public function maximumCostMinor(AnalysisInputData $input): int
        {
            return 1;
        }

        public function analyze(AnalysisInputData $input): AiAnalysisData
        {
            $this->tracker->calls++;

            throw new RuntimeException('This call must not happen.');
        }
    };

    expect(fn () => app(AnalysisProviderEvaluator::class)->evaluate(
        GoldenAnalysisDataset::load(
            app(AnalysisProviderEvaluationConfiguration::class),
        ),
        $analyzer,
        'bounded-test',
        'bounded-model',
        GOLDEN_EVALUATION_RELEASE,
        false,
    ))->toThrow(RuntimeException::class)
        ->and($tracker->calls)->toBe(0);
});

test('a reservation overrun stops the evaluation immediately', function () {
    $tracker = (object) ['calls' => 0];
    $analyzer = new class($tracker) implements ConfiguredListingAiAnalyzer
    {
        public function __construct(public object $tracker) {}

        public function isConfigured(): bool
        {
            return true;
        }

        public function provider(): string
        {
            return 'overrun-test';
        }

        public function model(): string
        {
            return 'overrun-model';
        }

        public function maximumCostMinor(AnalysisInputData $input): int
        {
            return 0;
        }

        public function analyze(AnalysisInputData $input): AiAnalysisData
        {
            $this->tracker->calls++;
            $listing = $input->requestPayload['listing'];

            return new AiAnalysisData(
                normalizedListing: [
                    'title' => trim($listing['title']),
                    'description' => isset($listing['description'])
                        ? trim($listing['description'])
                        : null,
                    'marketplace_name' => $listing['marketplace_name'],
                    'asking_price_minor' => $listing['asking_price_minor'],
                    'currency_code' => $listing['currency_code'],
                    'source_country_code' => $input->requestPayload['market_scope']['source_country_code'],
                    'target_country_code' => $input->requestPayload['market_scope']['target_country_code'],
                    'evidence_count' => count($input->requestPayload['evidence']),
                ],
                confidenceBasisPoints: 7500,
                estimatedCostMinor: 1,
            );
        }
    };

    expect(fn () => app(AnalysisProviderEvaluator::class)->evaluate(
        GoldenAnalysisDataset::load(
            app(AnalysisProviderEvaluationConfiguration::class),
        ),
        $analyzer,
        'overrun-test',
        'overrun-model',
        GOLDEN_EVALUATION_RELEASE,
        false,
    ))->toThrow(RuntimeException::class)
        ->and($tracker->calls)->toBe(1);
});

test('unknown dataset fields and path traversal fail closed', function () {
    $payload = goldenEvaluationPayload();
    $payload['cases'][0]['input']['listing']['seller_email'] = 'not-allowed@example.test';

    expect(fn () => GoldenAnalysisDataset::fromPayload(
        $payload,
        app(AnalysisProviderEvaluationConfiguration::class),
        str_repeat('a', 64),
    ))->toThrow(RuntimeException::class);

    config()->set(
        'analyses.provider_evaluation.dataset_path',
        'resources/analysis/../outside.json',
    );

    expect(fn () => app(
        AnalysisProviderEvaluationConfiguration::class,
    )->datasetPath())->toThrow(RuntimeException::class);
});
