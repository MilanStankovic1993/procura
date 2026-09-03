<?php

namespace App\EstimateAccuracy\Calculators;

use App\Enums\EstimateAccuracy\EstimateAccuracyStatus;
use App\Enums\Outcomes\ActualCostCategory;
use App\Enums\Profit\CostCategory;
use App\EstimateAccuracy\Contracts\EstimateAccuracyCalculator;
use App\EstimateAccuracy\Data\EstimateAccuracyReportData;
use App\Models\Currency;
use App\Models\ProfitEstimate;
use App\Models\RealizedProfit;
use App\Pricing\Contracts\ExchangeRateResolver;
use App\Pricing\MinorMoneyConverter;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use JsonException;
use LogicException;

final class DeterministicEstimateAccuracyCalculator implements EstimateAccuracyCalculator
{
    private const METRICS = [
        'purchase_price',
        'additional_costs',
        'sale_price',
        'net_profit',
    ];

    public function __construct(
        private readonly ExchangeRateResolver $rates,
        private readonly MinorMoneyConverter $converter,
    ) {}

    /**
     * @throws JsonException
     */
    public function calculate(
        ProfitEstimate $estimate,
        RealizedProfit $realizedProfit,
    ): EstimateAccuracyReportData {
        $estimate->loadMissing(['items', 'currency']);
        $realizedProfit->loadMissing('costSnapshot.items');
        $calculatedAt = CarbonImmutable::now()->utc();
        $conversion = $this->rates->resolve(
            $estimate->currency_code,
            $realizedProfit->currency_code,
            $estimate->calculation_at,
        );
        $reasonCodes = [$conversion->reasonCode];
        $unavailable = ['sale_duration'];
        $reasonCodes[] = 'expected_sale_duration_unavailable';
        $sourceExpected = [
            'purchase_price' => $this->requiredAmount(
                $estimate->purchase_price_minor,
                'purchase price',
            ),
            'additional_costs' => $this->requiredAmount(
                $estimate->additional_costs_minor,
                'additional costs',
            ),
            'sale_price' => $estimate->expected_sale_price_minor,
            'net_profit' => $this->requiredAmount(
                $estimate->expected_net_profit_minor,
                'net profit',
            ),
        ];
        $actual = [
            'purchase_price' => $realizedProfit->purchase_price_minor,
            'additional_costs' => $realizedProfit->actual_costs_minor,
            'sale_price' => $realizedProfit->sale_price_minor,
            'net_profit' => $realizedProfit->net_profit_minor,
        ];
        $comparableAdditionalCosts = $this->additionalCostsAreComparable(
            $estimate,
            $realizedProfit,
        );
        $metrics = [];

        foreach (self::METRICS as $metric) {
            $expected = $conversion->isResolved()
                ? $this->convertSigned(
                    $sourceExpected[$metric],
                    $estimate->currency,
                    $realizedProfit->currency_code,
                    (string) $conversion->rateValue,
                )
                : null;
            $comparable = $conversion->isResolved()
                && ($metric !== 'additional_costs' || $comparableAdditionalCosts);

            $metrics[$metric] = $this->metric(
                $sourceExpected[$metric],
                $expected,
                $actual[$metric],
                $comparable,
                $reasonCodes,
                $unavailable,
                $metric,
            );
        }

        if (! $conversion->isResolved()) {
            $status = EstimateAccuracyStatus::Unavailable;
        } elseif (! $comparableAdditionalCosts) {
            $status = EstimateAccuracyStatus::Partial;
            $reasonCodes[] = 'additional_cost_categories_not_comparable';
        } else {
            $status = EstimateAccuracyStatus::Calculated;
            $reasonCodes[] = 'monetary_accuracy_calculated';
        }

        $inputSnapshot = [
            'calculation_version' => (string) config(
                'estimate_accuracy.calculation_version',
            ),
            'profit_estimate' => [
                'id' => $estimate->getKey(),
                'analysis_id' => $estimate->analysis_id,
                'input_hash' => $estimate->input_hash,
                'currency_code' => $estimate->currency_code,
                'calculation_at' => $estimate->calculation_at->toIso8601String(),
                'expected' => $sourceExpected,
                'item_hashes' => $estimate->items
                    ->mapWithKeys(fn ($item): array => [
                        $item->category => hash('sha256', json_encode(
                            $item->source_snapshot,
                            JSON_THROW_ON_ERROR
                                | JSON_UNESCAPED_SLASHES
                                | JSON_UNESCAPED_UNICODE,
                        )),
                    ])
                    ->all(),
            ],
            'realized_profit' => [
                'id' => $realizedProfit->getKey(),
                'input_hash' => $realizedProfit->input_hash,
                'currency_code' => $realizedProfit->currency_code,
                'actual' => $actual,
                'actual_cost_snapshot_id' => (
                    $realizedProfit->actual_cost_snapshot_id
                ),
                'actual_cost_item_hashes' => (
                    $realizedProfit->costSnapshot->items
                        ->mapWithKeys(fn ($item): array => [
                            $item->category->value => $item->evidence_hash,
                        ])
                        ->all()
                ),
                'sale_duration_seconds' => (
                    $realizedProfit->sale_duration_seconds
                ),
            ],
            'conversion' => $conversion->toArray(),
            'comparability' => [
                'additional_costs' => $comparableAdditionalCosts,
            ],
        ];

        return new EstimateAccuracyReportData(
            status: $status,
            calculationVersion: (string) config(
                'estimate_accuracy.calculation_version',
            ),
            conversion: $conversion,
            metrics: $metrics,
            reasonCodes: array_values(array_unique($reasonCodes)),
            unavailableMetrics: array_values(array_unique($unavailable)),
            inputSnapshot: $inputSnapshot,
            inputHash: $this->hash($inputSnapshot),
            calculatedAt: $calculatedAt,
        );
    }

    private function requiredAmount(?int $amount, string $label): int
    {
        if ($amount === null) {
            throw new LogicException(
                "A complete profit estimate requires {$label}.",
            );
        }

        return $amount;
    }

    private function additionalCostsAreComparable(
        ProfitEstimate $estimate,
        RealizedProfit $realizedProfit,
    ): bool {
        $safetyReserve = $estimate->items->first(
            fn ($item): bool => $item->category
                === CostCategory::SafetyReserve->value,
        );
        $marketing = $realizedProfit->costSnapshot->items->first(
            fn ($item): bool => $item->category
                === ActualCostCategory::Marketing,
        );

        return (int) ($safetyReserve?->amount_minor ?? 0) === 0
            && (int) ($marketing?->reporting_amount_minor ?? 0) === 0;
    }

    /**
     * @param  list<string>  $reasonCodes
     * @param  list<string>  $unavailable
     * @return array<string, int|null>
     */
    private function metric(
        int $sourceExpected,
        ?int $expected,
        int $actual,
        bool $comparable,
        array &$reasonCodes,
        array &$unavailable,
        string $metric,
    ): array {
        if (! $comparable || $expected === null) {
            $unavailable[] = $metric;

            return [
                'source_expected_minor' => $sourceExpected,
                'expected_minor' => $expected,
                'actual_minor' => $actual,
                'signed_error_minor' => null,
                'absolute_error_minor' => null,
                'signed_error_basis_points' => null,
                'absolute_percentage_error_basis_points' => null,
            ];
        }

        $signed = $actual - $expected;
        $absolute = abs($signed);
        $signedBasisPoints = null;

        if ($expected === 0) {
            $reasonCodes[] = "{$metric}_percentage_error_zero_denominator";
        } else {
            $signedBasisPoints = BigInteger::of($signed)
                ->multipliedBy(10000)
                ->dividedBy(abs($expected), RoundingMode::HalfEven)
                ->toInt();

            if (abs($signedBasisPoints) > (int) config(
                'estimate_accuracy.maximum_percentage_error_basis_points',
            )) {
                $signedBasisPoints = null;
                $reasonCodes[] = (
                    "{$metric}_percentage_error_outside_supported_range"
                );
            }
        }

        return [
            'source_expected_minor' => $sourceExpected,
            'expected_minor' => $expected,
            'actual_minor' => $actual,
            'signed_error_minor' => $signed,
            'absolute_error_minor' => $absolute,
            'signed_error_basis_points' => $signedBasisPoints,
            'absolute_percentage_error_basis_points' => (
                $signedBasisPoints === null
                    ? null
                    : abs($signedBasisPoints)
            ),
        ];
    }

    private function convertSigned(
        int $amount,
        Currency $source,
        string $targetCurrencyCode,
        string $rate,
    ): int {
        $target = $source->getKey() === $targetCurrencyCode
            ? $source
            : Currency::query()
                ->where('active', true)
                ->find($targetCurrencyCode);

        if ($target === null || ! $source->active) {
            throw new LogicException(
                'Estimate accuracy requires active source and reporting currencies.',
            );
        }

        $converted = $this->converter->convert(
            abs($amount),
            $source->minor_unit,
            $target->minor_unit,
            $rate,
        );

        return $amount < 0 ? -$converted : $converted;
    }

    /** @param array<string, mixed> $value */
    private function hash(array $value): string
    {
        return hash('sha256', json_encode(
            $value,
            JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE,
        ));
    }
}
