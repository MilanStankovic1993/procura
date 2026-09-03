<?php

namespace App\Pricing\Resolvers;

use App\Enums\Pricing\ExchangeRateDirection;
use App\Enums\Pricing\ExchangeRateResolutionStatus;
use App\Models\ExchangeRate;
use App\Pricing\Contracts\ExchangeRateResolver;
use App\Pricing\Data\ExchangeRateResolutionData;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

class DatedExchangeRateResolver implements ExchangeRateResolver
{
    public function resolve(
        string $sourceCurrencyCode,
        string $targetCurrencyCode,
        CarbonImmutable $calculationAt,
    ): ExchangeRateResolutionData {
        $sourceCurrencyCode = strtoupper(trim($sourceCurrencyCode));
        $targetCurrencyCode = strtoupper(trim($targetCurrencyCode));

        if ($sourceCurrencyCode === $targetCurrencyCode) {
            return new ExchangeRateResolutionData(
                status: ExchangeRateResolutionStatus::Resolved,
                direction: ExchangeRateDirection::Identity,
                sourceCurrencyCode: $sourceCurrencyCode,
                targetCurrencyCode: $targetCurrencyCode,
                exchangeRateId: null,
                rateValue: '1.000000000000000000',
                effectiveAt: null,
                provider: null,
                providerReference: null,
                reasonCode: 'identity_currency_conversion',
            );
        }

        $candidates = [];
        $direct = $this->latestKnownRate(
            $sourceCurrencyCode,
            $targetCurrencyCode,
            $calculationAt,
        );
        $inverse = $this->latestKnownRate(
            $targetCurrencyCode,
            $sourceCurrencyCode,
            $calculationAt,
        );

        if ($direct !== null) {
            $candidates[] = [
                'direction' => ExchangeRateDirection::Direct,
                'record' => $direct,
            ];
        }

        if ($inverse !== null) {
            $candidates[] = [
                'direction' => ExchangeRateDirection::Inverse,
                'record' => $inverse,
            ];
        }

        usort($candidates, static function (array $left, array $right): int {
            $time = $right['record']->effective_at->getTimestamp()
                <=> $left['record']->effective_at->getTimestamp();

            if ($time !== 0) {
                return $time;
            }

            return $left['direction'] === ExchangeRateDirection::Direct ? -1 : 1;
        });
        $candidate = $candidates[0] ?? null;

        if ($candidate === null) {
            return new ExchangeRateResolutionData(
                status: ExchangeRateResolutionStatus::Missing,
                direction: ExchangeRateDirection::Unresolved,
                sourceCurrencyCode: $sourceCurrencyCode,
                targetCurrencyCode: $targetCurrencyCode,
                exchangeRateId: null,
                rateValue: null,
                effectiveAt: null,
                provider: null,
                providerReference: null,
                reasonCode: 'exchange_rate_missing',
            );
        }

        /** @var ExchangeRate $rate */
        $rate = $candidate['record'];
        /** @var ExchangeRateDirection $direction */
        $direction = $candidate['direction'];
        $normalizedRate = $direction === ExchangeRateDirection::Direct
            ? $rate->rate
            : (string) BigDecimal::one()->dividedBy(
                $rate->rate,
                18,
                RoundingMode::HalfEven,
            );
        $staleBefore = $calculationAt->subHours(
            (int) config('price_estimation.max_rate_age_hours'),
        );
        $status = $rate->effective_at->lt($staleBefore)
            ? ExchangeRateResolutionStatus::Stale
            : ExchangeRateResolutionStatus::Resolved;

        return new ExchangeRateResolutionData(
            status: $status,
            direction: $direction,
            sourceCurrencyCode: $sourceCurrencyCode,
            targetCurrencyCode: $targetCurrencyCode,
            exchangeRateId: $rate->getKey(),
            rateValue: $normalizedRate,
            effectiveAt: $rate->effective_at,
            provider: $rate->provider,
            providerReference: $rate->provider_reference,
            reasonCode: $status === ExchangeRateResolutionStatus::Resolved
                ? 'dated_exchange_rate_resolved'
                : 'exchange_rate_stale',
        );
    }

    private function latestKnownRate(
        string $baseCurrencyCode,
        string $quoteCurrencyCode,
        CarbonImmutable $calculationAt,
    ): ?ExchangeRate {
        return ExchangeRate::query()
            ->where('base_currency_code', $baseCurrencyCode)
            ->where('quote_currency_code', $quoteCurrencyCode)
            ->where('effective_at', '<=', $calculationAt)
            ->where('fetched_at', '<=', $calculationAt)
            ->orderByDesc('effective_at')
            ->orderByDesc('fetched_at')
            ->orderBy('id')
            ->limit(1)
            ->first();
    }
}
