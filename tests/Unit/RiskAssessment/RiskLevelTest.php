<?php

use App\Enums\Risk\RiskLevel;

test('risk score bands use the documented inclusive boundaries', function (
    int $score,
    RiskLevel $expected,
) {
    expect(RiskLevel::fromScore($score))->toBe($expected);
})->with([
    'low minimum' => [0, RiskLevel::Low],
    'low maximum' => [24, RiskLevel::Low],
    'medium minimum' => [25, RiskLevel::Medium],
    'medium maximum' => [49, RiskLevel::Medium],
    'high minimum' => [50, RiskLevel::High],
    'high maximum' => [74, RiskLevel::High],
    'critical minimum' => [75, RiskLevel::Critical],
    'critical maximum' => [100, RiskLevel::Critical],
]);
