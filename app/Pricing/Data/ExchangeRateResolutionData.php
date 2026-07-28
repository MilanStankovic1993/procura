<?php

namespace App\Pricing\Data;

use App\Enums\Pricing\ExchangeRateDirection;
use App\Enums\Pricing\ExchangeRateResolutionStatus;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class ExchangeRateResolutionData
{
    public function __construct(
        public ExchangeRateResolutionStatus $status,
        public ExchangeRateDirection $direction,
        public string $sourceCurrencyCode,
        public string $targetCurrencyCode,
        public ?string $exchangeRateId,
        public ?string $rateValue,
        public ?CarbonImmutable $effectiveAt,
        public ?string $provider,
        public ?string $providerReference,
        public string $reasonCode,
    ) {
        if (
            $status === ExchangeRateResolutionStatus::Resolved
            && $rateValue === null
        ) {
            throw new InvalidArgumentException(
                'A resolved exchange rate requires a normalized rate value.',
            );
        }

        if (
            $direction === ExchangeRateDirection::Identity
            && $sourceCurrencyCode !== $targetCurrencyCode
        ) {
            throw new InvalidArgumentException(
                'Identity conversion requires the same source and target currency.',
            );
        }
    }

    public function isResolved(): bool
    {
        return $this->status === ExchangeRateResolutionStatus::Resolved;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'direction' => $this->direction->value,
            'source_currency_code' => $this->sourceCurrencyCode,
            'target_currency_code' => $this->targetCurrencyCode,
            'exchange_rate_id' => $this->exchangeRateId,
            'rate_value' => $this->rateValue,
            'effective_at' => $this->effectiveAt?->toIso8601String(),
            'provider' => $this->provider,
            'provider_reference' => $this->providerReference,
            'reason_code' => $this->reasonCode,
        ];
    }
}
