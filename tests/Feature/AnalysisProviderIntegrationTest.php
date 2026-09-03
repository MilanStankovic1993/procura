<?php

use App\Analysis\Contracts\ListingAiAnalyzer;
use App\Analysis\Data\AnalysisInputData;
use App\Analysis\Governance\AnalysisProviderGovernanceConfiguration;
use App\Analysis\Providers\GeminiListingAiAnalyzer;
use App\Analysis\Providers\OpenAiListingAiAnalyzer;
use App\Exceptions\AnalysisProviderException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function externalAnalysisInput(): AnalysisInputData
{
    return new AnalysisInputData(
        analysisId: '01K00000000000000000000000',
        inputHash: str_repeat('a', 64),
        requestPayload: [
            'listing' => [
                'id' => 'internal-listing-secret',
                'snapshot_id' => 'internal-snapshot-secret',
                'source_url' => 'https://private.example/listing-secret',
                'external_id' => 'external-secret',
                'marketplace_name' => 'Test Market',
                'title' => '  Bosch drill  ',
                'description' => ' Two batteries and charger. ',
                'asking_price_minor' => 12999,
                'currency_code' => 'EUR',
                'seller_information' => 'seller-personal-secret',
                'location' => 'seller-location-secret',
            ],
            'market_scope' => [
                'source_country_code' => 'AT',
                'target_country_code' => 'DE',
            ],
            'evidence' => [[
                'id' => 'internal-evidence-secret',
                'kind' => 'product',
                'checksum_sha256' => str_repeat('b', 64),
                'mime_type' => 'image/jpeg',
                'width' => 1200,
                'height' => 900,
            ]],
        ],
    );
}

function externalAnalysisResultJson(): string
{
    return json_encode([
        'normalized_title' => 'Bosch drill',
        'normalized_description' => 'Two batteries and charger.',
        'confidence_basis_points' => 8750,
    ], JSON_THROW_ON_ERROR);
}

test('analysis provider governance rejects an unsafe budget hierarchy', function () {
    config()->set('analyses.provider_governance.task_max_cost_minor', 20);
    config()->set('analyses.provider_governance.user_monthly_budget_minor', 10);

    expect(app(AnalysisProviderGovernanceConfiguration::class)->isValid())
        ->toBeFalse();
});

test('gemini staging provider sends minimized structured input and maps usage', function () {
    config([
        'analyses.providers.gemini.api_key' => 'gemini-test-secret',
        'analyses.providers.gemini.input_price_usd_per_million' => '0',
        'analyses.providers.gemini.output_price_usd_per_million' => '0',
    ]);
    Http::fake([
        'https://generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [[
                'finishReason' => 'STOP',
                'content' => [
                    'parts' => [['text' => externalAnalysisResultJson()]],
                ],
            ]],
            'usageMetadata' => [
                'promptTokenCount' => 420,
                'candidatesTokenCount' => 80,
            ],
        ]),
    ]);

    $provider = app(GeminiListingAiAnalyzer::class);
    expect($provider->maximumCostMinor(externalAnalysisInput()))->toBe(0);
    $result = $provider->analyze(externalAnalysisInput());

    expect($result->normalizedListing)->toMatchArray([
        'title' => 'Bosch drill',
        'description' => 'Two batteries and charger.',
        'asking_price_minor' => 12999,
        'currency_code' => 'EUR',
        'source_country_code' => 'AT',
        'target_country_code' => 'DE',
        'evidence_count' => 1,
    ])->and($result->confidenceBasisPoints)->toBe(8750)
        ->and($result->needsInput)->toBe([])
        ->and($result->tokensIn)->toBe(420)
        ->and($result->tokensOut)->toBe(80)
        ->and($result->estimatedCostMinor)->toBe(0);

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();
        $modelInput = (string) data_get(
            $payload,
            'contents.0.parts.0.text',
        );

        expect($request->url())->toContain(
            '/models/gemini-3.7-flash:generateContent',
        )->and($request->header('x-goog-api-key'))->toBe([
            'gemini-test-secret',
        ])->and(data_get(
            $payload,
            'generationConfig.responseMimeType',
        ))->toBe('application/json')
            ->and($modelInput)->toContain('Bosch drill')
            ->not->toContain('internal-listing-secret')
            ->not->toContain('internal-snapshot-secret')
            ->not->toContain('private.example')
            ->not->toContain('external-secret')
            ->not->toContain('seller-personal-secret')
            ->not->toContain('seller-location-secret')
            ->not->toContain('internal-evidence-secret')
            ->not->toContain(str_repeat('b', 64));

        return true;
    });
});

test('openai production provider uses non-stored strict responses and records cost', function () {
    config([
        'analyses.providers.openai.api_key' => 'openai-test-secret',
        'analyses.providers.openai.input_price_usd_per_million' => '0.10',
        'analyses.providers.openai.output_price_usd_per_million' => '0.60',
    ]);
    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'status' => 'completed',
            'model' => 'gpt-5.6-luna',
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => externalAnalysisResultJson(),
                ]],
            ]],
            'usage' => [
                'input_tokens' => 1_000_000,
                'output_tokens' => 1_000_000,
            ],
        ]),
    ]);

    $provider = app(OpenAiListingAiAnalyzer::class);
    expect($provider->maximumCostMinor(externalAnalysisInput()))
        ->toBeGreaterThanOrEqual(1)
        ->toBeLessThanOrEqual(
            config('analyses.provider_governance.task_max_cost_minor'),
        );
    $result = $provider->analyze(externalAnalysisInput());

    expect($result->tokensIn)->toBe(1_000_000)
        ->and($result->tokensOut)->toBe(1_000_000)
        ->and($result->estimatedCostMinor)->toBe(70)
        ->and($result->estimatedCostCurrency)->toBe('USD');

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();

        expect($request->hasHeader('Authorization', 'Bearer openai-test-secret'))
            ->toBeTrue()
            ->and($request->hasHeader('Idempotency-Key'))->toBeTrue()
            ->and($payload['store'])->toBeFalse()
            ->and($payload['truncation'])->toBe('disabled')
            ->and(data_get($payload, 'text.format.type'))->toBe('json_schema')
            ->and(data_get($payload, 'text.format.strict'))->toBeTrue()
            ->and((string) $payload['input'])->not->toContain(
                'seller-personal-secret',
            );

        return true;
    });
});

test('external providers fail closed before any request when credentials are missing', function () {
    config([
        'analyses.providers.gemini.api_key' => '',
        'analyses.providers.openai.api_key' => '',
    ]);
    Http::preventStrayRequests();

    foreach ([
        GeminiListingAiAnalyzer::class,
        OpenAiListingAiAnalyzer::class,
    ] as $provider) {
        try {
            app($provider)->analyze(externalAnalysisInput());
            $this->fail('The provider accepted an empty credential.');
        } catch (AnalysisProviderException $exception) {
            expect($exception->reasonCode)->toBe(
                'analysis_provider_not_configured',
            )->and($exception->getMessage())->not->toContain('secret');
        }
    }

    Http::assertNothingSent();
});

test('provider errors and malformed structured output expose no response body', function () {
    config(['analyses.providers.gemini.api_key' => 'gemini-test-secret']);
    Http::fake([
        'https://generativelanguage.googleapis.com/*' => Http::sequence()
            ->push([
                'error' => ['message' => 'provider-sensitive-body'],
            ], 429)
            ->push([
                'candidates' => [[
                    'finishReason' => 'STOP',
                    'content' => [
                        'parts' => [['text' => '{invalid-json']],
                    ],
                ]],
                'usageMetadata' => [
                    'promptTokenCount' => 10,
                    'candidatesTokenCount' => 10,
                ],
            ]),
    ]);

    try {
        app(GeminiListingAiAnalyzer::class)->analyze(externalAnalysisInput());
        $this->fail('The provider rejection was accepted.');
    } catch (AnalysisProviderException $exception) {
        expect($exception->reasonCode)->toBe('analysis_provider_rate_limited')
            ->and($exception->providerStatus)->toBe(429)
            ->and($exception->getMessage())
            ->not->toContain('provider-sensitive-body');
    }

    try {
        app(GeminiListingAiAnalyzer::class)->analyze(externalAnalysisInput());
        $this->fail('The malformed provider response was accepted.');
    } catch (AnalysisProviderException $exception) {
        expect($exception->reasonCode)->toBe(
            'analysis_provider_response_invalid',
        );
    }
});

test('provider server errors are sanitized and classified for monitoring', function () {
    config(['analyses.providers.openai.api_key' => 'openai-test-secret']);
    Http::fake([
        'https://api.openai.com/*' => Http::response([
            'error' => ['message' => 'provider-private-outage-body'],
        ], 503),
    ]);

    try {
        app(OpenAiListingAiAnalyzer::class)->analyze(externalAnalysisInput());
        $this->fail('The provider server failure was accepted.');
    } catch (AnalysisProviderException $exception) {
        expect($exception->reasonCode)->toBe('analysis_provider_server_error')
            ->and($exception->providerStatus)->toBe(503)
            ->and($exception->getMessage())
            ->not->toContain('provider-private-outage-body');
    }
});

test('the configured provider binding selects gemini or openai explicitly', function () {
    config(['analyses.provider' => 'gemini']);
    expect(app(ListingAiAnalyzer::class))
        ->toBeInstanceOf(GeminiListingAiAnalyzer::class);

    config(['analyses.provider' => 'openai']);
    expect(app(ListingAiAnalyzer::class))
        ->toBeInstanceOf(OpenAiListingAiAnalyzer::class);
});
