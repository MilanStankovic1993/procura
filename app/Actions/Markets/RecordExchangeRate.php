<?php

namespace App\Actions\Markets;

use App\Models\Currency;
use App\Models\ExchangeRate;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RecordExchangeRate
{
    /**
     * @param  array<string, mixed>  $rawEvidence
     * @return array{rate: ExchangeRate, created: bool}
     */
    public function record(
        string $baseCurrencyCode,
        string $quoteCurrencyCode,
        string $rateValue,
        string $provider,
        string $providerReference,
        string $evidenceHash,
        CarbonImmutable $effectiveAt,
        ?CarbonImmutable $publishedAt,
        CarbonImmutable $fetchedAt,
        array $rawEvidence,
    ): array {
        $baseCurrencyCode = strtoupper(trim($baseCurrencyCode));
        $quoteCurrencyCode = strtoupper(trim($quoteCurrencyCode));
        $provider = str($provider)->lower()->squish()->toString();
        $providerReference = trim($providerReference);

        if (
            strlen($baseCurrencyCode) !== 3
            || strlen($quoteCurrencyCode) !== 3
            || $baseCurrencyCode === $quoteCurrencyCode
        ) {
            throw new InvalidArgumentException(
                'Exchange rates require two different ISO 4217 currency codes.',
            );
        }

        if (
            Currency::query()
                ->whereIn('code', [$baseCurrencyCode, $quoteCurrencyCode])
                ->count() !== 2
        ) {
            throw new InvalidArgumentException(
                'Both exchange-rate currencies must exist in reference data.',
            );
        }

        if (
            $provider === ''
            || strlen($provider) > 80
            || $providerReference === ''
            || strlen($providerReference) > 160
        ) {
            throw new InvalidArgumentException(
                'Exchange-rate provider identity is missing or exceeds its storage limit.',
            );
        }

        if (! preg_match('/\A[a-f0-9]{64}\z/i', $evidenceHash)) {
            throw new InvalidArgumentException(
                'Exchange-rate evidence requires a SHA-256 hash.',
            );
        }

        $rate = BigDecimal::of(trim($rateValue))
            ->toScale(18, RoundingMode::Unnecessary);

        if (
            $rate->isLessThanOrEqualTo(0)
            || $rate->isGreaterThanOrEqualTo('1000000000000')
        ) {
            throw new InvalidArgumentException(
                'Exchange rates must be positive and fit decimal(30,18).',
            );
        }

        $effectiveAt = $effectiveAt->utc();
        $publishedAt = $publishedAt?->utc();
        $fetchedAt = $fetchedAt->utc();

        if (
            $effectiveAt->gt($fetchedAt)
            || ($publishedAt !== null && $publishedAt->gt($fetchedAt))
        ) {
            throw new InvalidArgumentException(
                'Exchange-rate source timestamps cannot occur after retrieval.',
            );
        }

        $normalizedRate = (string) $rate;
        $encodedEvidence = json_encode(
            [
                'base_currency_code' => $baseCurrencyCode,
                'quote_currency_code' => $quoteCurrencyCode,
                'rate' => $normalizedRate,
                'provider' => $provider,
                'provider_reference' => $providerReference,
                'evidence_hash' => strtolower($evidenceHash),
                'effective_at' => $effectiveAt->toIso8601String(),
                'published_at' => $publishedAt?->toIso8601String(),
                'fetched_at' => $fetchedAt->toIso8601String(),
                'raw_evidence' => $rawEvidence,
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        $rateKey = hash('sha256', $encodedEvidence);

        return DB::transaction(function () use (
            $baseCurrencyCode,
            $quoteCurrencyCode,
            $normalizedRate,
            $provider,
            $providerReference,
            $rateKey,
            $evidenceHash,
            $rawEvidence,
            $effectiveAt,
            $publishedAt,
            $fetchedAt,
        ): array {
            $record = ExchangeRate::query()->firstOrCreate(
                ['rate_key' => $rateKey],
                [
                    'base_currency_code' => $baseCurrencyCode,
                    'quote_currency_code' => $quoteCurrencyCode,
                    'rate' => $normalizedRate,
                    'provider' => $provider,
                    'provider_reference' => $providerReference,
                    'evidence_hash' => strtolower($evidenceHash),
                    'raw_evidence' => $rawEvidence,
                    'effective_at' => $effectiveAt,
                    'published_at' => $publishedAt,
                    'fetched_at' => $fetchedAt,
                ],
            );

            return [
                'rate' => $record,
                'created' => $record->wasRecentlyCreated,
            ];
        }, attempts: 3);
    }
}
