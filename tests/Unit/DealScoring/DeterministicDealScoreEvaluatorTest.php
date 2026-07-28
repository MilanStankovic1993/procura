<?php

use App\DealScoring\Data\DealScoreInputData;
use App\DealScoring\Evaluators\DeterministicDealScoreEvaluator;
use App\Enums\DealScoring\DealRecommendation;
use App\Enums\DealScoring\DealScoreStatus;
use App\Enums\Pricing\PriceConfidenceLevel;
use App\Enums\Risk\RiskLevel;
use Tests\TestCase;

uses(TestCase::class);

function dealScoreInput(array $overrides = []): DealScoreInputData
{
    return new DealScoreInputData(
        profitMarginBasisPoints: (
            $overrides['profitMarginBasisPoints'] ?? 4000
        ),
        profitConfidenceBasisPoints: (
            $overrides['profitConfidenceBasisPoints'] ?? 10000
        ),
        priceConfidenceBasisPoints: array_key_exists(
            'priceConfidenceBasisPoints',
            $overrides,
        )
            ? $overrides['priceConfidenceBasisPoints']
            : 10000,
        priceConfidenceLevel: array_key_exists(
            'priceConfidenceLevel',
            $overrides,
        )
            ? $overrides['priceConfidenceLevel']
            : PriceConfidenceLevel::High,
        demandScore: array_key_exists('demandScore', $overrides)
            ? $overrides['demandScore']
            : 100,
        demandConfidenceBasisPoints: (
            $overrides['demandConfidenceBasisPoints'] ?? 10000
        ),
        riskScore: array_key_exists('riskScore', $overrides)
            ? $overrides['riskScore']
            : 0,
        riskLevel: array_key_exists('riskLevel', $overrides)
            ? $overrides['riskLevel']
            : RiskLevel::Low,
        riskConfidenceBasisPoints: (
            $overrides['riskConfidenceBasisPoints'] ?? 10000
        ),
        logisticsScore: array_key_exists('logisticsScore', $overrides)
            ? $overrides['logisticsScore']
            : 100,
        logisticsConfidenceBasisPoints: (
            $overrides['logisticsConfidenceBasisPoints'] ?? 10000
        ),
        productModelKnown: $overrides['productModelKnown'] ?? true,
        sourceSnapshots: [],
        verificationActions: [],
    );
}

test('deal score v1 weights five perfect components to exactly one hundred', function () {
    $result = app(DeterministicDealScoreEvaluator::class)
        ->evaluate(dealScoreInput());

    expect($result->status)->toBe(DealScoreStatus::Assessed)
        ->and($result->uncappedScoreBasisPoints)->toBe(10000)
        ->and($result->uncappedScore)->toBe(100)
        ->and($result->scoreBasisPoints)->toBe(10000)
        ->and($result->score)->toBe(100)
        ->and($result->recommendation)
        ->toBe(DealRecommendation::StrongOpportunity)
        ->and($result->confidenceBasisPoints)->toBe(10000)
        ->and(array_sum(array_column(
            $result->items,
            'weight_basis_points',
        )))->toBe(10000)
        ->and(array_sum(array_column(
            $result->items,
            'weighted_contribution_basis_points',
        )))->toBe(10000);
});

test('margin normalization preserves negative zero linear and ceiling evidence', function (
    int $margin,
    int $normalized,
    int $scoreBasisPoints,
    string $reason,
) {
    $result = app(DeterministicDealScoreEvaluator::class)->evaluate(
        dealScoreInput(['profitMarginBasisPoints' => $margin]),
    );
    $marginItem = $result->items[0];

    expect($marginItem['raw_value'])->toBe($margin)
        ->and($marginItem['normalized_score_basis_points'])
        ->toBe($normalized)
        ->and($result->scoreBasisPoints)->toBe($scoreBasisPoints)
        ->and($result->reasonCodes)->toContain($reason);
})->with([
    'negative margin' => [-100, 0, 6500, 'negative_expected_margin'],
    'zero margin' => [0, 0, 6500, 'zero_expected_margin'],
    'halfway margin' => [2000, 5000, 8250, 'margin_linearly_normalized'],
    'ceiling margin' => [
        4000,
        10000,
        10000,
        'margin_normalization_ceiling_reached',
    ],
    'above ceiling margin' => [
        5000,
        10000,
        10000,
        'margin_normalization_ceiling_reached',
    ],
]);

test('deal score uses deterministic half up integer rounding', function () {
    $result = app(DeterministicDealScoreEvaluator::class)->evaluate(
        dealScoreInput(['profitMarginBasisPoints' => 1]),
    );

    expect($result->items[0]['normalized_score_basis_points'])->toBe(3)
        ->and($result->items[0]['weighted_contribution_basis_points'])
        ->toBe(1)
        ->and($result->scoreBasisPoints)->toBe(6501)
        ->and($result->score)->toBe(65);
});

test('every documented cap is explicit and the lowest applicable cap wins', function () {
    $critical = app(DeterministicDealScoreEvaluator::class)->evaluate(
        dealScoreInput([
            'riskScore' => 75,
            'riskLevel' => RiskLevel::Critical,
        ]),
    );
    $lowPrice = app(DeterministicDealScoreEvaluator::class)->evaluate(
        dealScoreInput([
            'priceConfidenceBasisPoints' => 6000,
            'priceConfidenceLevel' => PriceConfidenceLevel::Low,
        ]),
    );
    $unknownModel = app(DeterministicDealScoreEvaluator::class)->evaluate(
        dealScoreInput(['productModelKnown' => false]),
    );
    $combined = app(DeterministicDealScoreEvaluator::class)->evaluate(
        dealScoreInput([
            'riskScore' => 75,
            'riskLevel' => RiskLevel::Critical,
            'priceConfidenceBasisPoints' => 6000,
            'priceConfidenceLevel' => PriceConfidenceLevel::Low,
            'productModelKnown' => false,
        ]),
    );

    expect($critical->score)->toBe(40)
        ->and($critical->applicableCap)->toBe(40)
        ->and($critical->reasonCodes)->toContain('critical_risk_cap_applied')
        ->and($lowPrice->score)->toBe(60)
        ->and($lowPrice->applicableCap)->toBe(60)
        ->and($lowPrice->reasonCodes)
        ->toContain('low_price_confidence_cap_applied')
        ->and($unknownModel->score)->toBe(50)
        ->and($unknownModel->applicableCap)->toBe(50)
        ->and($unknownModel->reasonCodes)
        ->toContain('unknown_product_model_cap_applied')
        ->and($combined->score)->toBe(40)
        ->and($combined->applicableCap)->toBe(40);
});

test('a missing required component produces insufficient data without precision', function () {
    $result = app(DeterministicDealScoreEvaluator::class)->evaluate(
        dealScoreInput(['demandScore' => null]),
    );

    expect($result->status)->toBe(DealScoreStatus::NeedsInput)
        ->and($result->uncappedScore)->toBeNull()
        ->and($result->score)->toBeNull()
        ->and($result->recommendation)
        ->toBe(DealRecommendation::InsufficientData)
        ->and($result->unknownCount)->toBe(1)
        ->and($result->reasonCodes)
        ->toContain('deal_score_resale_demand_missing');
});

test('recommendation thresholds use final exact score basis points', function (
    int $basisPoints,
    DealRecommendation $recommendation,
) {
    expect(DealRecommendation::fromScoreBasisPoints($basisPoints))
        ->toBe($recommendation);
})->with([
    'avoid minimum' => [0, DealRecommendation::Avoid],
    'avoid maximum' => [2999, DealRecommendation::Avoid],
    'weak minimum' => [3000, DealRecommendation::WeakOpportunity],
    'weak maximum' => [4999, DealRecommendation::WeakOpportunity],
    'verification minimum' => [5000, DealRecommendation::NeedsVerification],
    'verification maximum' => [6499, DealRecommendation::NeedsVerification],
    'potential minimum' => [6500, DealRecommendation::PotentialOpportunity],
    'potential maximum' => [7999, DealRecommendation::PotentialOpportunity],
    'strong minimum' => [8000, DealRecommendation::StrongOpportunity],
    'strong maximum' => [10000, DealRecommendation::StrongOpportunity],
]);

test('deal score rejects out of range component evidence', function () {
    expect(
        fn () => app(DeterministicDealScoreEvaluator::class)->evaluate(
            dealScoreInput(['riskScore' => 101]),
        ),
    )->toThrow(InvalidArgumentException::class);
});
