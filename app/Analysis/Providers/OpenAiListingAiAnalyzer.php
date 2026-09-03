<?php

namespace App\Analysis\Providers;

use App\Analysis\Contracts\ConfiguredListingAiAnalyzer;
use App\Analysis\Data\AiAnalysisData;
use App\Analysis\Data\AnalysisInputData;
use App\Analysis\ExternalAnalysisProviderConfiguration;
use App\Analysis\ListingAnalysisPrompt;
use App\Analysis\StructuredListingAnalysisMapper;
use App\Exceptions\AnalysisProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;

final class OpenAiListingAiAnalyzer implements ConfiguredListingAiAnalyzer
{
    private readonly ExternalAnalysisProviderConfiguration $configuration;

    public function __construct(
        private readonly ListingAnalysisPrompt $prompt,
        private readonly StructuredListingAnalysisMapper $mapper,
    ) {
        $this->configuration = new ExternalAnalysisProviderConfiguration(
            'openai',
        );
    }

    public function isConfigured(): bool
    {
        return $this->configuration->isConfigured();
    }

    public function model(): string
    {
        return $this->configuration->model();
    }

    public function provider(): string
    {
        return $this->configuration->provider();
    }

    public function maximumCostMinor(AnalysisInputData $input): int
    {
        $this->configuration->assertConfigured();
        $requestBytes = strlen(json_encode(
            $this->request($input),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        ));

        return $this->configuration->estimatedCostMinor(
            $requestBytes,
            $this->configuration->maxOutputTokens(),
        );
    }

    public function analyze(AnalysisInputData $input): AiAnalysisData
    {
        $this->configuration->assertConfigured();

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withToken($this->configuration->apiKey())
                ->withHeaders([
                    'Idempotency-Key' => $this->idempotencyKey($input),
                ])
                ->connectTimeout(
                    $this->configuration->connectTimeoutSeconds(),
                )
                ->timeout($this->configuration->timeoutSeconds())
                ->post($this->endpoint(), $this->request($input));
        } catch (ConnectionException) {
            throw new AnalysisProviderException(
                'analysis_provider_transport_failed',
            );
        }

        $this->assertSuccessful($response);
        $content = $this->outputText($response);
        $tokensIn = $response->json('usage.input_tokens');
        $tokensOut = $response->json('usage.output_tokens');

        if (
            $response->json('status') !== 'completed'
            || ! is_string($content)
            || strlen($content) > 65536
            || ! is_int($tokensIn)
            || ! is_int($tokensOut)
        ) {
            $this->invalidResponse($response->status());
        }

        try {
            $result = json_decode(
                $content,
                true,
                32,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            $this->invalidResponse($response->status());
        }

        if (! is_array($result)) {
            $this->invalidResponse($response->status());
        }

        return $this->mapper->map(
            $result,
            $input,
            $tokensIn,
            $tokensOut,
            $this->configuration->estimatedCostMinor(
                $tokensIn,
                $tokensOut,
            ),
        );
    }

    /** @return array<string, mixed> */
    private function request(AnalysisInputData $input): array
    {
        return [
            'model' => $this->configuration->model(),
            'instructions' => $this->prompt->instructions(),
            'input' => json_encode(
                $this->prompt->payload($input),
                JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE,
            ),
            'max_output_tokens' => $this->configuration->maxOutputTokens(),
            'store' => false,
            'truncation' => 'disabled',
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'procura_listing_normalization',
                    'strict' => true,
                    'schema' => $this->prompt->schema(),
                ],
                'verbosity' => 'low',
            ],
        ];
    }

    private function endpoint(): string
    {
        return $this->configuration->baseUrl().'/responses';
    }

    private function idempotencyKey(AnalysisInputData $input): string
    {
        return 'procura-analysis-'.hash(
            'sha256',
            $input->analysisId
                .'|'
                .$input->inputHash
                .'|'
                .config('analyses.prompt_version'),
        );
    }

    private function outputText(Response $response): mixed
    {
        $output = $response->json('output');

        if (! is_array($output)) {
            return null;
        }

        foreach ($output as $item) {
            if (! is_array($item) || ($item['type'] ?? null) !== 'message') {
                continue;
            }

            foreach (($item['content'] ?? []) as $content) {
                if (
                    is_array($content)
                    && ($content['type'] ?? null) === 'output_text'
                ) {
                    return $content['text'] ?? null;
                }
            }
        }

        return null;
    }

    private function assertSuccessful(Response $response): void
    {
        if (! $response->successful()) {
            throw new AnalysisProviderException(
                'analysis_provider_rejected',
                $response->status(),
            );
        }
    }

    private function invalidResponse(?int $status = null): never
    {
        throw new AnalysisProviderException(
            'analysis_provider_response_invalid',
            $status,
        );
    }
}
