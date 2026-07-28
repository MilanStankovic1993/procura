<?php

use App\Enums\EstimateAccuracy\EstimateAccuracyStatus;
use App\Enums\Pricing\ExchangeRateDirection;
use App\Enums\Pricing\ExchangeRateResolutionStatus;
use App\EstimateAccuracy\Calculators\DeterministicEstimateAccuracyCalculator;
use App\Models\ActualCostItem;
use App\Models\ActualCostSnapshot;
use App\Models\Currency;
use App\Models\ProfitEstimate;
use App\Models\ProfitEstimateItem;
use App\Models\RealizedProfit;
use App\Pricing\Contracts\ExchangeRateResolver;
use App\Pricing\Data\ExchangeRateResolutionData;
use App\Pricing\MinorMoneyConverter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;

uses(TestCase::class);

function accuracyCalculatorEvidence(
    string $estimateCurrency = 'EUR',
    int $expectedNetProfit = 7000,
    int $marketingMinor = 0,
): array {
    $currency = new Currency([
        'code' => $estimateCurrency,
        'name' => $estimateCurrency,
        'minor_unit' => 2,
        'active' => true,
    ]);
    $estimate = new ProfitEstimate([
        'analysis_id' => '01JTESTANALYSIS00000000000',
        'input_hash' => str_repeat('a', 64),
        'currency_code' => $estimateCurrency,
        'calculation_at' => CarbonImmutable::parse(
            '2026-07-01T10:00:00Z',
        ),
        'purchase_price_minor' => 10000,
        'additional_costs_minor' => 3000,
        'expected_sale_price_minor' => 20000,
        'expected_net_profit_minor' => $expectedNetProfit,
    ]);
    $estimate->id = '01JTESTESTIMATE0000000000';
    $estimate->setRelation('currency', $currency);
    $estimate->setRelation('items', new Collection([
        new ProfitEstimateItem([
            'category' => 'safety_reserve',
            'amount_minor' => 0,
            'source_snapshot' => ['source' => 'unit-test'],
        ]),
    ]));
    $costSnapshot = new ActualCostSnapshot;
    $costSnapshot->id = '01JTESTCOSTSNAPSHOT000000';
    $costSnapshot->setRelation('items', new Collection([
        new ActualCostItem([
            'category' => 'marketing',
            'reporting_amount_minor' => $marketingMinor,
            'evidence_hash' => str_repeat('b', 64),
        ]),
    ]));
    $realized = new RealizedProfit([
        'input_hash' => str_repeat('c', 64),
        'currency_code' => 'EUR',
        'purchase_price_minor' => 11000,
        'actual_costs_minor' => 3500,
        'sale_price_minor' => 19000,
        'net_profit_minor' => 4500,
        'sale_duration_seconds' => 172800,
        'actual_cost_snapshot_id' => $costSnapshot->getKey(),
    ]);
    $realized->id = '01JTESTREALIZED0000000000';
    $realized->setRelation('costSnapshot', $costSnapshot);

    return [$estimate, $realized];
}

function accuracyRateResolution(
    ExchangeRateResolutionStatus $status,
    string $source = 'EUR',
    string $target = 'EUR',
): ExchangeRateResolutionData {
    $resolved = $status === ExchangeRateResolutionStatus::Resolved;

    return new ExchangeRateResolutionData(
        status: $status,
        direction: $resolved
            ? ExchangeRateDirection::Identity
            : ExchangeRateDirection::Unresolved,
        sourceCurrencyCode: $source,
        targetCurrencyCode: $target,
        exchangeRateId: null,
        rateValue: $resolved ? '1.000000000000000000' : null,
        effectiveAt: null,
        provider: null,
        providerReference: null,
        reasonCode: $resolved
            ? 'identity_currency_conversion'
            : ($status === ExchangeRateResolutionStatus::Stale
                ? 'exchange_rate_stale'
                : 'exchange_rate_missing'),
    );
}

test('deterministic estimate accuracy stores signed absolute and bounded percentage errors', function () {
    [$estimate, $realized] = accuracyCalculatorEvidence();
    $rates = Mockery::mock(ExchangeRateResolver::class);
    $rates->shouldReceive('resolve')
        ->once()
        ->andReturn(accuracyRateResolution(
            ExchangeRateResolutionStatus::Resolved,
        ));
    $result = (new DeterministicEstimateAccuracyCalculator(
        $rates,
        new MinorMoneyConverter,
    ))->calculate($estimate, $realized);

    expect($result->status)->toBe(EstimateAccuracyStatus::Calculated)
        ->and($result->metrics['purchase_price'])
        ->toMatchArray([
            'signed_error_minor' => 1000,
            'absolute_error_minor' => 1000,
            'signed_error_basis_points' => 1000,
            'absolute_percentage_error_basis_points' => 1000,
        ])
        ->and($result->metrics['additional_costs'])
        ->toMatchArray([
            'signed_error_minor' => 500,
            'absolute_error_minor' => 500,
            'signed_error_basis_points' => 1667,
            'absolute_percentage_error_basis_points' => 1667,
        ])
        ->and($result->metrics['sale_price'])
        ->toMatchArray([
            'signed_error_minor' => -1000,
            'absolute_error_minor' => 1000,
            'signed_error_basis_points' => -500,
            'absolute_percentage_error_basis_points' => 500,
        ])
        ->and($result->metrics['net_profit'])
        ->toMatchArray([
            'signed_error_minor' => -2500,
            'absolute_error_minor' => 2500,
            'signed_error_basis_points' => -3571,
            'absolute_percentage_error_basis_points' => 3571,
        ])
        ->and($result->unavailableMetrics)->toBe(['sale_duration'])
        ->and($result->inputHash)->toHaveLength(64);
});

test('missing or stale exchange-rate evidence never produces converted accuracy', function (
    ExchangeRateResolutionStatus $status,
) {
    [$estimate, $realized] = accuracyCalculatorEvidence('USD');
    $rates = Mockery::mock(ExchangeRateResolver::class);
    $rates->shouldReceive('resolve')
        ->once()
        ->andReturn(accuracyRateResolution($status, 'USD', 'EUR'));
    $result = (new DeterministicEstimateAccuracyCalculator(
        $rates,
        new MinorMoneyConverter,
    ))->calculate($estimate, $realized);

    expect($result->status)->toBe(EstimateAccuracyStatus::Unavailable)
        ->and($result->metrics['purchase_price']['expected_minor'])->toBeNull()
        ->and($result->metrics['purchase_price']['signed_error_minor'])->toBeNull()
        ->and($result->unavailableMetrics)->toContain(
            'purchase_price',
            'additional_costs',
            'sale_price',
            'net_profit',
            'sale_duration',
        );
})->with([
    ExchangeRateResolutionStatus::Missing,
    ExchangeRateResolutionStatus::Stale,
]);

test('non-comparable cost categories and zero denominators remain explicit', function () {
    [$estimate, $realized] = accuracyCalculatorEvidence(
        expectedNetProfit: 0,
        marketingMinor: 200,
    );
    $rates = Mockery::mock(ExchangeRateResolver::class);
    $rates->shouldReceive('resolve')
        ->once()
        ->andReturn(accuracyRateResolution(
            ExchangeRateResolutionStatus::Resolved,
        ));
    $result = (new DeterministicEstimateAccuracyCalculator(
        $rates,
        new MinorMoneyConverter,
    ))->calculate($estimate, $realized);

    expect($result->status)->toBe(EstimateAccuracyStatus::Partial)
        ->and($result->metrics['additional_costs']['signed_error_minor'])
        ->toBeNull()
        ->and($result->metrics['net_profit']['signed_error_minor'])->toBe(4500)
        ->and($result->metrics['net_profit']['signed_error_basis_points'])
        ->toBeNull()
        ->and($result->reasonCodes)->toContain(
            'additional_cost_categories_not_comparable',
            'net_profit_percentage_error_zero_denominator',
        );
});
