<?php

use App\BrokerRequests\BrokerCommissionCalculator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

test('broker commission calculation is exact half up integer arithmetic', function () {
    $calculator = app(BrokerCommissionCalculator::class);

    expect($calculator->calculate(1, 'commission:v1', 5000))->toBe([
        'commission_rule_version' => 'commission:v1',
        'commission_rate_basis_points' => 5000,
        'commission_base_minor' => 1,
        'commission_amount_minor' => 1,
        'payable_total_minor' => 2,
    ])->and($calculator->calculate(9999, 'commission:v1', 1))->toBe([
        'commission_rule_version' => 'commission:v1',
        'commission_rate_basis_points' => 1,
        'commission_base_minor' => 9999,
        'commission_amount_minor' => 1,
        'payable_total_minor' => 10000,
    ])->and($calculator->calculate(10000, 'commission:v1', 1))->toBe([
        'commission_rule_version' => 'commission:v1',
        'commission_rate_basis_points' => 1,
        'commission_base_minor' => 10000,
        'commission_amount_minor' => 1,
        'payable_total_minor' => 10001,
    ]);
});

test('broker commission calculation rejects invalid policy and unsafe payable totals', function () {
    $calculator = app(BrokerCommissionCalculator::class);

    expect(fn () => $calculator->calculate(100, '', 250))
        ->toThrow(ValidationException::class);
    expect(fn () => $calculator->calculate(
        BrokerCommissionCalculator::MAX_SAFE_MINOR,
        'commission:v1',
        1,
    ))->toThrow(ValidationException::class);
});
