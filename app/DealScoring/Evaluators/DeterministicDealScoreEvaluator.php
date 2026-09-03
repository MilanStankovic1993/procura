<?php

namespace App\DealScoring\Evaluators;

use App\DealScoring\Contracts\DealScoreEvaluator;
use App\DealScoring\Data\DealScoreData;
use App\DealScoring\Data\DealScoreInputData;
use App\Enums\DealScoring\DealRecommendation;
use App\Enums\DealScoring\DealScoreComponent;
use App\Enums\DealScoring\DealScoreConfidenceLevel;
use App\Enums\DealScoring\DealScoreImpact;
use App\Enums\DealScoring\DealScoreStatus;
use App\Enums\Pricing\PriceConfidenceLevel;
use App\Enums\Risk\RiskLevel;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class DeterministicDealScoreEvaluator implements DealScoreEvaluator
{
    public function evaluate(DealScoreInputData $input): DealScoreData
    {
        $this->validate($input);

        $version = (string) config('deal_scoring.calculation_version');
        $weights = config('deal_scoring.weights_basis_points');
        $reasonCodes = [];
        $marginNormalized = $this->normalizeMargin(
            $input->profitMarginBasisPoints,
            $reasonCodes,
        );
        $normalized = [
            DealScoreComponent::EstimatedNetMargin->value => $marginNormalized,
            DealScoreComponent::PriceConfidence->value => (
                $input->priceConfidenceBasisPoints
            ),
            DealScoreComponent::ResaleDemand->value => (
                $input->demandScore === null
                    ? null
                    : $input->demandScore * 100
            ),
            DealScoreComponent::InverseRisk->value => (
                $input->riskScore === null
                    ? null
                    : (100 - $input->riskScore) * 100
            ),
            DealScoreComponent::LogisticsSimplicity->value => (
                $input->logisticsScore === null
                    ? null
                    : $input->logisticsScore * 100
            ),
        ];
        $rawValues = [
            DealScoreComponent::EstimatedNetMargin->value => (
                $input->profitMarginBasisPoints
            ),
            DealScoreComponent::PriceConfidence->value => (
                $input->priceConfidenceBasisPoints
            ),
            DealScoreComponent::ResaleDemand->value => $input->demandScore,
            DealScoreComponent::InverseRisk->value => $input->riskScore,
            DealScoreComponent::LogisticsSimplicity->value => (
                $input->logisticsScore
            ),
        ];
        $rawUnits = [
            DealScoreComponent::EstimatedNetMargin->value => 'basis_points',
            DealScoreComponent::PriceConfidence->value => 'basis_points',
            DealScoreComponent::ResaleDemand->value => 'points',
            DealScoreComponent::InverseRisk->value => 'risk_points',
            DealScoreComponent::LogisticsSimplicity->value => 'points',
        ];
        $confidences = [
            DealScoreComponent::EstimatedNetMargin->value => (
                $input->profitConfidenceBasisPoints
            ),
            DealScoreComponent::PriceConfidence->value => (
                $input->priceConfidenceBasisPoints ?? 0
            ),
            DealScoreComponent::ResaleDemand->value => (
                $input->demandConfidenceBasisPoints
            ),
            DealScoreComponent::InverseRisk->value => (
                $input->riskConfidenceBasisPoints
            ),
            DealScoreComponent::LogisticsSimplicity->value => (
                $input->logisticsConfidenceBasisPoints
            ),
        ];
        $items = [];
        $confidenceComponents = [];
        $factorsIncreasing = [];
        $factorsReducing = [];

        foreach (DealScoreComponent::cases() as $index => $component) {
            $code = $component->value;
            $normalizedScore = $normalized[$code];
            $weight = (int) $weights[$code];
            $contribution = $normalizedScore === null
                ? null
                : $this->divideHalfUp($normalizedScore * $weight, 10000);
            $confidenceContribution = $this->divideHalfUp(
                $confidences[$code] * $weight,
                10000,
            );
            $confidenceComponents[$code] = $confidenceContribution;
            $impact = $this->impact($normalizedScore);

            if ($impact === DealScoreImpact::Strengthens) {
                $factorsIncreasing[] = "{$code}_strengthens_score";
            } elseif ($impact === DealScoreImpact::Reduces) {
                $factorsReducing[] = "{$code}_reduces_score";
            }

            if ($normalizedScore === null) {
                $reasonCodes[] = "deal_score_{$code}_missing";
            }

            $items[] = [
                'position' => $index + 1,
                'component' => $component,
                'weight_basis_points' => $weight,
                'raw_value' => $rawValues[$code],
                'raw_value_unit' => $rawUnits[$code],
                'normalized_score_basis_points' => $normalizedScore,
                'weighted_contribution_basis_points' => $contribution,
                'confidence_basis_points' => $confidences[$code],
                'impact' => $impact,
                'source_snapshot' => $input->sourceSnapshots[$code] ?? [],
            ];
        }

        $unknownCount = count(array_filter(
            $normalized,
            static fn (?int $value): bool => $value === null,
        ));
        $confidence = min(10000, array_sum($confidenceComponents));
        $capDecisions = $this->capDecisions($input);
        $applicableCaps = array_values(array_map(
            static fn (array $decision): int => $decision['maximum_score'],
            array_filter(
                $capDecisions,
                static fn (array $decision): bool => $decision['triggered'],
            ),
        ));
        $applicableCap = $applicableCaps === []
            ? null
            : min($applicableCaps);
        $uncappedBasisPoints = $unknownCount === 0
            ? array_sum(array_column(
                $items,
                'weighted_contribution_basis_points',
            ))
            : null;
        $scoreBasisPoints = $uncappedBasisPoints === null
            ? null
            : min(
                $uncappedBasisPoints,
                ($applicableCap ?? 100) * 100,
            );

        foreach ($capDecisions as &$decision) {
            $decision['applied'] = $uncappedBasisPoints !== null
                && $decision['triggered']
                && $uncappedBasisPoints > ($decision['maximum_score'] * 100);

            if ($decision['triggered']) {
                $reasonCodes[] = "{$decision['code']}_cap_triggered";
            }

            if ($decision['applied']) {
                $reasonCodes[] = "{$decision['code']}_cap_applied";
                $factorsReducing[] = "{$decision['code']}_cap_reduces_score";
            }
        }
        unset($decision);

        $status = $unknownCount === 0
            ? DealScoreStatus::Assessed
            : DealScoreStatus::NeedsInput;
        $recommendation = $scoreBasisPoints === null
            ? DealRecommendation::InsufficientData
            : DealRecommendation::fromScoreBasisPoints($scoreBasisPoints);
        $reasonCodes[] = $unknownCount === 0
            ? 'weighted_deal_score_calculated'
            : 'required_deal_score_component_missing';
        $reasonCodes[] = "recommendation_{$recommendation->value}";
        $assumptions = [
            'expected_sale_price_is_realized',
            'confirmed_costs_remain_accurate',
            'demand_observation_is_representative',
            'recorded_logistics_conditions_do_not_change',
        ];
        $verificationActions = $this->verificationActions(
            $input,
            $normalized,
        );
        $snapshot = [
            'calculation_version' => $version,
            'margin_normalization' => [
                'floor_basis_points' => (int) config(
                    'deal_scoring.margin_floor_basis_points',
                ),
                'ceiling_basis_points' => (int) config(
                    'deal_scoring.margin_ceiling_basis_points',
                ),
                'raw_margin_basis_points' => (
                    $input->profitMarginBasisPoints
                ),
            ],
            'weights_basis_points' => $weights,
            'caps' => config('deal_scoring.caps'),
            'recommendation_minimum_basis_points' => config(
                'deal_scoring.recommendation_minimum_basis_points',
            ),
            'product_model_known' => $input->productModelKnown,
            'sources' => $input->sourceSnapshots,
            'items' => array_map(
                static fn (array $item): array => [
                    'component' => $item['component']->value,
                    'weight_basis_points' => $item['weight_basis_points'],
                    'raw_value' => $item['raw_value'],
                    'raw_value_unit' => $item['raw_value_unit'],
                    'normalized_score_basis_points' => (
                        $item['normalized_score_basis_points']
                    ),
                    'weighted_contribution_basis_points' => (
                        $item['weighted_contribution_basis_points']
                    ),
                    'confidence_basis_points' => (
                        $item['confidence_basis_points']
                    ),
                    'source_snapshot' => $item['source_snapshot'],
                ],
                $items,
            ),
        ];

        return new DealScoreData(
            status: $status,
            calculationVersion: $version,
            inputHash: hash(
                'sha256',
                json_encode(
                    $snapshot,
                    JSON_THROW_ON_ERROR
                        | JSON_UNESCAPED_SLASHES
                        | JSON_UNESCAPED_UNICODE,
                ),
            ),
            calculatedAt: CarbonImmutable::now()->utc(),
            uncappedScore: $this->wholeScore($uncappedBasisPoints),
            uncappedScoreBasisPoints: $uncappedBasisPoints,
            score: $this->wholeScore($scoreBasisPoints),
            scoreBasisPoints: $scoreBasisPoints,
            recommendation: $recommendation,
            confidenceBasisPoints: $confidence,
            confidenceLevel: DealScoreConfidenceLevel::fromBasisPoints(
                $confidence,
            ),
            unknownCount: $unknownCount,
            applicableCap: $applicableCap,
            capDecisions: $capDecisions,
            reasonCodes: array_values(array_unique($reasonCodes)),
            confidenceComponents: $confidenceComponents,
            factorsIncreasing: array_values(array_unique($factorsIncreasing)),
            factorsReducing: array_values(array_unique($factorsReducing)),
            assumptions: $assumptions,
            verificationActions: $verificationActions,
            inputSnapshot: $snapshot,
            items: $items,
        );
    }

    /** @param  list<string>  $reasonCodes */
    private function normalizeMargin(
        ?int $marginBasisPoints,
        array &$reasonCodes,
    ): ?int {
        if ($marginBasisPoints === null) {
            return null;
        }

        $floor = (int) config('deal_scoring.margin_floor_basis_points');
        $ceiling = (int) config(
            'deal_scoring.margin_ceiling_basis_points',
        );

        if ($marginBasisPoints < $floor) {
            $reasonCodes[] = 'negative_expected_margin';

            return 0;
        }

        if ($marginBasisPoints === $floor) {
            $reasonCodes[] = 'zero_expected_margin';

            return 0;
        }

        if ($marginBasisPoints >= $ceiling) {
            $reasonCodes[] = 'margin_normalization_ceiling_reached';

            return 10000;
        }

        $reasonCodes[] = 'margin_linearly_normalized';

        return $this->divideHalfUp(
            ($marginBasisPoints - $floor) * 10000,
            $ceiling - $floor,
        );
    }

    private function impact(?int $scoreBasisPoints): DealScoreImpact
    {
        if ($scoreBasisPoints === null) {
            return DealScoreImpact::Unknown;
        }

        return match (true) {
            $scoreBasisPoints >= (int) config(
                'deal_scoring.strengthens_score_basis_points',
            ) => DealScoreImpact::Strengthens,
            $scoreBasisPoints <= (int) config(
                'deal_scoring.reduces_score_basis_points',
            ) => DealScoreImpact::Reduces,
            default => DealScoreImpact::Neutral,
        };
    }

    /** @return list<array<string, mixed>> */
    private function capDecisions(DealScoreInputData $input): array
    {
        $caps = config('deal_scoring.caps');

        return [
            [
                'code' => 'critical_risk',
                'maximum_score' => (int) $caps['critical_risk'],
                'triggered' => $input->riskLevel === RiskLevel::Critical,
                'applied' => false,
                'evidence' => [
                    'risk_level' => $input->riskLevel?->value,
                    'risk_score' => $input->riskScore,
                ],
            ],
            [
                'code' => 'low_price_confidence',
                'maximum_score' => (int) $caps['low_price_confidence'],
                'triggered' => (
                    $input->priceConfidenceLevel
                        === PriceConfidenceLevel::Low
                ),
                'applied' => false,
                'evidence' => [
                    'price_confidence_level' => (
                        $input->priceConfidenceLevel?->value
                    ),
                    'price_confidence_basis_points' => (
                        $input->priceConfidenceBasisPoints
                    ),
                ],
            ],
            [
                'code' => 'unknown_product_model',
                'maximum_score' => (int) $caps['unknown_product_model'],
                'triggered' => ! $input->productModelKnown,
                'applied' => false,
                'evidence' => [
                    'product_model_known' => $input->productModelKnown,
                ],
            ],
        ];
    }

    /**
     * @param  array<string, ?int>  $normalized
     * @return list<string>
     */
    private function verificationActions(
        DealScoreInputData $input,
        array $normalized,
    ): array {
        $actions = $input->verificationActions;

        if (($input->profitMarginBasisPoints ?? 0) <= 0) {
            $actions[] = 'Do not proceed unless the purchase price or recorded costs improve.';
        }

        if (
            $input->priceConfidenceLevel === PriceConfidenceLevel::Low
            || $input->priceConfidenceBasisPoints === null
        ) {
            $actions[] = 'Add recent, diverse and attributable price comparables.';
        }

        if (
            ($normalized[DealScoreComponent::ResaleDemand->value] ?? 0)
                < 6500
        ) {
            $actions[] = 'Verify more completed-sale observations and resale timing.';
        }

        if (
            ($normalized[DealScoreComponent::LogisticsSimplicity->value] ?? 0)
                < 6500
        ) {
            $actions[] = 'Confirm delivery, insurance, packaging and handover conditions.';
        }

        if (! $input->productModelKnown) {
            $actions[] = 'Confirm the canonical product model before acting on the score.';
        }

        return array_values(array_unique($actions));
    }

    private function validate(DealScoreInputData $input): void
    {
        $basisPointValues = [
            'profit confidence' => $input->profitConfidenceBasisPoints,
            'price confidence' => $input->priceConfidenceBasisPoints,
            'demand confidence' => $input->demandConfidenceBasisPoints,
            'risk confidence' => $input->riskConfidenceBasisPoints,
            'logistics confidence' => (
                $input->logisticsConfidenceBasisPoints
            ),
        ];

        foreach ($basisPointValues as $label => $value) {
            if ($value !== null && ($value < 0 || $value > 10000)) {
                throw new InvalidArgumentException(
                    "The {$label} must be between 0 and 10000 basis points.",
                );
            }
        }

        foreach ([
            'demand score' => $input->demandScore,
            'risk score' => $input->riskScore,
            'logistics score' => $input->logisticsScore,
        ] as $label => $value) {
            if ($value !== null && ($value < 0 || $value > 100)) {
                throw new InvalidArgumentException(
                    "The {$label} must be between 0 and 100.",
                );
            }
        }

        if (
            $input->profitMarginBasisPoints !== null
            && abs($input->profitMarginBasisPoints) > (int) config(
                'deal_scoring.maximum_absolute_margin_basis_points',
            )
        ) {
            throw new InvalidArgumentException(
                'The profit margin exceeds the DealScore safety bound.',
            );
        }

        if (
            array_sum(config('deal_scoring.weights_basis_points')) !== 10000
        ) {
            throw new InvalidArgumentException(
                'DealScore component weights must total 10000 basis points.',
            );
        }
    }

    private function divideHalfUp(int $numerator, int $denominator): int
    {
        return intdiv($numerator + intdiv($denominator, 2), $denominator);
    }

    private function wholeScore(?int $basisPoints): ?int
    {
        return $basisPoints === null
            ? null
            : $this->divideHalfUp($basisPoints, 100);
    }
}
