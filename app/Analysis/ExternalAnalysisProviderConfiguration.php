<?php

namespace App\Analysis;

use App\Exceptions\AnalysisProviderException;
use Throwable;

final class ExternalAnalysisProviderConfiguration
{
    private const PROVIDERS = ['gemini', 'openai'];

    public function __construct(private readonly string $provider)
    {
        if (! in_array($provider, self::PROVIDERS, true)) {
            throw new \InvalidArgumentException('Unsupported external analysis provider.');
        }
    }

    public function isConfigured(): bool
    {
        try {
            $this->assertConfigured();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function assertConfigured(): void
    {
        $url = parse_url($this->baseUrl());
        $allowedHosts = $this->value('allowed_hosts');

        if (
            $this->apiKey() === ''
            || ! is_array($url)
            || ($url['scheme'] ?? null) !== 'https'
            || ! is_string($url['host'] ?? null)
            || ! is_array($allowedHosts)
            || ! in_array(strtolower($url['host']), $allowedHosts, true)
            || ! preg_match('/^[a-z0-9][a-z0-9._-]{1,119}$/', $this->model())
            || $this->timeoutSeconds() < 1
            || $this->timeoutSeconds() > 120
            || $this->connectTimeoutSeconds() < 1
            || $this->connectTimeoutSeconds() > $this->timeoutSeconds()
            || $this->maxOutputTokens() < 128
            || $this->maxOutputTokens() > 4096
        ) {
            throw new AnalysisProviderException(
                'analysis_provider_not_configured',
            );
        }

        $this->priceMicroDollars('input_price_usd_per_million');
        $this->priceMicroDollars('output_price_usd_per_million');
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function apiKey(): string
    {
        return trim((string) $this->value('api_key'));
    }

    public function baseUrl(): string
    {
        return rtrim(trim((string) $this->value('base_url')), '/');
    }

    public function model(): string
    {
        return strtolower(trim((string) $this->value('model')));
    }

    public function timeoutSeconds(): int
    {
        return (int) $this->value('timeout_seconds');
    }

    public function connectTimeoutSeconds(): int
    {
        return (int) $this->value('connect_timeout_seconds');
    }

    public function maxOutputTokens(): int
    {
        return (int) $this->value('max_output_tokens');
    }

    public function estimatedCostMinor(int $tokensIn, int $tokensOut): int
    {
        if ($tokensIn < 0 || $tokensOut < 0) {
            throw new AnalysisProviderException(
                'analysis_provider_response_invalid',
            );
        }

        $microDollarTokenCost = (
            $tokensIn * $this->priceMicroDollars(
                'input_price_usd_per_million',
            )
        ) + (
            $tokensOut * $this->priceMicroDollars(
                'output_price_usd_per_million',
            )
        );

        if ($microDollarTokenCost === 0) {
            return 0;
        }

        // Rates are micro-dollars per one million tokens; round up to USD cents.
        return intdiv(
            $microDollarTokenCost + 9_999_999_999,
            10_000_000_000,
        );
    }

    private function value(string $key): mixed
    {
        return config("analyses.providers.{$this->provider}.{$key}");
    }

    private function priceMicroDollars(string $key): int
    {
        $value = trim((string) $this->value($key));

        if (! preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/', $value)) {
            throw new AnalysisProviderException(
                'analysis_provider_not_configured',
            );
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $microDollars = ((int) $whole * 1_000_000)
            + (int) str_pad($fraction, 6, '0');

        if ($microDollars > 100_000_000) {
            throw new AnalysisProviderException(
                'analysis_provider_not_configured',
            );
        }

        return $microDollars;
    }
}
