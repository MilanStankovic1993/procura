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

final class GeminiListingAiAnalyzer implements ConfiguredListingAiAnalyzer
{
    private readonly ExternalAnalysisProviderConfiguration $configuration;

    public function __construct(
        private readonly ListingAnalysisPrompt $prompt,
        private readonly StructuredListingAnalysisMapper $mapper,
    ) {
        $this->configuration = new ExternalAnalysisProviderConfiguration(
            'gemini',
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
                ->withHeaders([
                    'x-goog-api-key' => $this->configuration->apiKey(),
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
        $content = $response->json('candidates.0.content.parts.0.text');
        $finishReason = $response->json('candidates.0.finishReason');
        $tokensIn = $response->json('usageMetadata.promptTokenCount');
        $tokensOut = $response->json('usageMetadata.candidatesTokenCount');

        if (
            ! is_string($content)
            || strlen($content) > 65536
            || $finishReason !== 'STOP'
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
            'systemInstruction' => [
                'parts' => [['text' => $this->prompt->instructions()]],
            ],
            'contents' => [[
                'role' => 'user',
                'parts' => [[
                    'text' => json_encode(
                        $this->prompt->payload($input),
                        JSON_THROW_ON_ERROR
                            | JSON_UNESCAPED_SLASHES
                            | JSON_UNESCAPED_UNICODE,
                    ),
                ]],
            ]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseJsonSchema' => $this->prompt->schema(),
                'maxOutputTokens' => $this->configuration->maxOutputTokens(),
                'temperature' => 0.1,
            ],
        ];
    }

    private function endpoint(): string
    {
        return $this->configuration->baseUrl()
            .'/models/'
            .rawurlencode($this->configuration->model())
            .':generateContent';
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
