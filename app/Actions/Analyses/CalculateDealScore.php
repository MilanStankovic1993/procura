<?php

namespace App\Actions\Analyses;

use App\DealScoring\Contracts\DealScoreEvaluator;
use App\DealScoring\Data\DealScoreInputData;
use App\Models\Analysis;
use App\Models\OpportunityAssessment;
use App\Models\ProductMatch;
use App\Models\ProfitEstimate;
use LogicException;

class CalculateDealScore
{
    public function __construct(
        private readonly DealScoreEvaluator $evaluator,
        private readonly RecordDealScore $scores,
        private readonly DealScoreResultProjection $projection,
    ) {}

    /**
     * @return array{analysis: Analysis, created: bool}
     */
    public function calculate(
        Analysis $analysis,
        ProfitEstimate $profitEstimate,
        OpportunityAssessment $logistics,
        OpportunityAssessment $demand,
    ): array {
        $profitEstimate->loadMissing([
            'priceEstimate',
            'riskAssessment',
        ]);
        $productMatch = ProductMatch::query()->findOrFail(
            $profitEstimate->riskAssessment->product_match_id,
        );

        if (
            $profitEstimate->analysis_id !== $analysis->getKey()
            || $logistics->analysis_id !== $analysis->getKey()
            || $demand->analysis_id !== $analysis->getKey()
            || $logistics->profit_estimate_id
                !== $profitEstimate->getKey()
            || $demand->profit_estimate_id !== $profitEstimate->getKey()
            || $logistics->opportunity_input_id
                !== $demand->opportunity_input_id
        ) {
            throw new LogicException(
                'DealScore calculation received a stale evidence chain.',
            );
        }

        $result = $this->evaluator->evaluate(new DealScoreInputData(
            profitMarginBasisPoints: (
                $profitEstimate->profit_margin_basis_points
            ),
            profitConfidenceBasisPoints: (
                $profitEstimate->confidence_basis_points
            ),
            priceConfidenceBasisPoints: (
                $profitEstimate->priceEstimate->confidence_basis_points
            ),
            priceConfidenceLevel: (
                $profitEstimate->priceEstimate->confidence_level
            ),
            demandScore: $demand->score,
            demandConfidenceBasisPoints: $demand->confidence_basis_points,
            riskScore: $profitEstimate->riskAssessment->score,
            riskLevel: $profitEstimate->riskAssessment->level,
            riskConfidenceBasisPoints: (
                $profitEstimate->riskAssessment->confidence_basis_points
            ),
            logisticsScore: $logistics->score,
            logisticsConfidenceBasisPoints: (
                $logistics->confidence_basis_points
            ),
            productModelKnown: $productMatch->product_model_id !== null,
            sourceSnapshots: [
                'estimated_net_margin' => [
                    'profit_estimate_id' => $profitEstimate->getKey(),
                    'input_hash' => $profitEstimate->input_hash,
                    'expected_net_profit_minor' => (
                        $profitEstimate->expected_net_profit_minor
                    ),
                    'profit_margin_basis_points' => (
                        $profitEstimate->profit_margin_basis_points
                    ),
                ],
                'price_confidence' => [
                    'price_estimate_id' => (
                        $profitEstimate->priceEstimate->getKey()
                    ),
                    'input_hash' => (
                        $profitEstimate->priceEstimate->input_hash
                    ),
                    'confidence_basis_points' => (
                        $profitEstimate
                            ->priceEstimate
                            ->confidence_basis_points
                    ),
                    'confidence_level' => (
                        $profitEstimate
                            ->priceEstimate
                            ->confidence_level
                            ->value
                    ),
                ],
                'resale_demand' => [
                    'opportunity_assessment_id' => $demand->getKey(),
                    'input_hash' => $demand->input_hash,
                    'score' => $demand->score,
                ],
                'inverse_risk' => [
                    'risk_assessment_id' => (
                        $profitEstimate->riskAssessment->getKey()
                    ),
                    'input_hash' => (
                        $profitEstimate->riskAssessment->input_hash
                    ),
                    'score' => $profitEstimate->riskAssessment->score,
                    'level' => (
                        $profitEstimate->riskAssessment->level->value
                    ),
                ],
                'logistics_simplicity' => [
                    'opportunity_assessment_id' => $logistics->getKey(),
                    'input_hash' => $logistics->input_hash,
                    'score' => $logistics->score,
                ],
            ],
            verificationActions: (
                $profitEstimate->riskAssessment->verification_actions
            ),
        ));
        $recorded = $this->scores->record(
            $analysis,
            $productMatch,
            $profitEstimate,
            $logistics,
            $demand,
            $result,
        );
        $projected = $this->projection->apply(
            $analysis,
            $recorded['score'],
        );

        return [
            'analysis' => $projected,
            'created' => $recorded['created'],
        ];
    }
}
