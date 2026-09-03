<?php

namespace App\OutcomeTracking;

use App\Enums\Pricing\ExchangeRateDirection;
use App\Enums\Validation\ApplicationValidationCode;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Pricing\MinorMoneyConverter;
use App\Support\Validation\ApplicationValidation;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

final class OutcomeMoneyNormalizer
{
    public function __construct(
        private readonly MinorMoneyConverter $converter,
    ) {}

    /**
     * @return array{
     *   source_amount_minor: int,
     *   source_currency_code: string,
     *   reporting_amount_minor: int,
     *   reporting_currency_code: string,
     *   exchange_rate_id: ?string,
     *   rate_direction: string,
     *   rate_value: string,
     *   rate_effective_at: ?CarbonImmutable,
     *   rate_provider: ?string,
     *   rate_provider_reference: ?string,
     *   conversion_calculated_at: CarbonImmutable
     * }
     */
    public function normalize(
        int $amountMinor,
        string $sourceCurrencyCode,
        string $reportingCurrencyCode,
        CarbonImmutable $occurredAt,
        string $errorField = 'reporting_currency_code',
    ): array {
        $sourceCurrencyCode = strtoupper(trim($sourceCurrencyCode));
        $reportingCurrencyCode = strtoupper(trim($reportingCurrencyCode));
        $currencies = Currency::query()
            ->whereIn('code', [
                $sourceCurrencyCode,
                $reportingCurrencyCode,
            ])
            ->where('active', true)
            ->get()
            ->keyBy('code');
        $source = $currencies->get($sourceCurrencyCode);
        $target = $currencies->get($reportingCurrencyCode);

        if ($source === null || $target === null) {
            ApplicationValidation::fail(
                $errorField,
                ApplicationValidationCode::InactiveOutcomeCurrency,
            );
        }

        $calculatedAt = CarbonImmutable::now()->utc();

        if ($sourceCurrencyCode === $reportingCurrencyCode) {
            return [
                'source_amount_minor' => $amountMinor,
                'source_currency_code' => $sourceCurrencyCode,
                'reporting_amount_minor' => $amountMinor,
                'reporting_currency_code' => $reportingCurrencyCode,
                'exchange_rate_id' => null,
                'rate_direction' => ExchangeRateDirection::Identity->value,
                'rate_value' => '1.000000000000000000',
                'rate_effective_at' => null,
                'rate_provider' => null,
                'rate_provider_reference' => null,
                'conversion_calculated_at' => $calculatedAt,
            ];
        }

        $candidate = $this->rateCandidate(
            $sourceCurrencyCode,
            $reportingCurrencyCode,
            $occurredAt,
            $calculatedAt,
        );

        if ($candidate === null) {
            ApplicationValidation::fail(
                $errorField,
                ApplicationValidationCode::MissingOutcomeExchangeRate,
            );
        }

        $rate = $candidate['record'];
        $direction = $candidate['direction'];
        $rateValue = $direction === ExchangeRateDirection::Direct
            ? (string) $rate->rate
            : (string) BigDecimal::one()->dividedBy(
                $rate->rate,
                18,
                RoundingMode::HalfEven,
            );
        $reportingAmount = $this->converter->convert(
            $amountMinor,
            $source->minor_unit,
            $target->minor_unit,
            $rateValue,
        );

        return [
            'source_amount_minor' => $amountMinor,
            'source_currency_code' => $sourceCurrencyCode,
            'reporting_amount_minor' => $reportingAmount,
            'reporting_currency_code' => $reportingCurrencyCode,
            'exchange_rate_id' => $rate->getKey(),
            'rate_direction' => $direction->value,
            'rate_value' => $rateValue,
            'rate_effective_at' => $rate->effective_at,
            'rate_provider' => $rate->provider,
            'rate_provider_reference' => $rate->provider_reference,
            'conversion_calculated_at' => $calculatedAt,
        ];
    }

    /**
     * @return array{
     *   direction: ExchangeRateDirection,
     *   record: ExchangeRate
     * }|null
     */
    private function rateCandidate(
        string $sourceCurrencyCode,
        string $reportingCurrencyCode,
        CarbonImmutable $occurredAt,
        CarbonImmutable $calculatedAt,
    ): ?array {
        $candidates = [];
        $direct = $this->latestRate(
            $sourceCurrencyCode,
            $reportingCurrencyCode,
            $occurredAt,
            $calculatedAt,
        );
        $inverse = $this->latestRate(
            $reportingCurrencyCode,
            $sourceCurrencyCode,
            $occurredAt,
            $calculatedAt,
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

            return $left['direction'] === ExchangeRateDirection::Direct
                ? -1
                : 1;
        });

        return $candidates[0] ?? null;
    }

    private function latestRate(
        string $baseCurrencyCode,
        string $quoteCurrencyCode,
        CarbonImmutable $occurredAt,
        CarbonImmutable $calculatedAt,
    ): ?ExchangeRate {
        return ExchangeRate::query()
            ->where('base_currency_code', $baseCurrencyCode)
            ->where('quote_currency_code', $quoteCurrencyCode)
            ->where('effective_at', '<=', $occurredAt)
            ->where('fetched_at', '<=', $calculatedAt)
            ->orderByDesc('effective_at')
            ->orderByDesc('fetched_at')
            ->orderBy('id')
            ->first();
    }
}
