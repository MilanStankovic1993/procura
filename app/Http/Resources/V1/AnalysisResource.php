<?php

namespace App\Http\Resources\V1;

use App\Enums\BuyerDecisions\BuyerDecisionState;
use App\Enums\DealScoring\DealScoreStatus;
use App\Models\Analysis;
use Illuminate\Http\Request;

/** @mixin Analysis */
class AnalysisResource extends AnalysisSummaryResource
{
    public function toArray(Request $request): array
    {
        $productMatch = $this->relationLoaded('currentProductMatch')
            ? $this->getRelation('currentProductMatch')
            : null;
        $priceEstimate = $this->relationLoaded('currentPriceEstimate')
            ? $this->getRelation('currentPriceEstimate')
            : null;
        $riskAssessment = $this->relationLoaded('currentRiskAssessment')
            ? $this->getRelation('currentRiskAssessment')
            : null;
        $costInput = $this->relationLoaded('currentCostInput')
            ? $this->getRelation('currentCostInput')
            : null;

        if (
            $costInput !== null
            && (
                $priceEstimate === null
                || $riskAssessment === null
                || $costInput->price_estimate_id !== $priceEstimate->getKey()
                || $costInput->risk_assessment_id !== $riskAssessment->getKey()
            )
        ) {
            $costInput = null;
        }

        $profitEstimate = $this->relationLoaded('currentProfitEstimate')
            ? $this->getRelation('currentProfitEstimate')
            : null;

        if (
            $profitEstimate !== null
            && (
                $costInput === null
                || $profitEstimate->cost_input_id !== $costInput->getKey()
            )
        ) {
            $profitEstimate = null;
        }

        $opportunityInput = $this->relationLoaded('currentOpportunityInput')
            ? $this->getRelation('currentOpportunityInput')
            : null;

        if (
            $opportunityInput !== null
            && (
                $profitEstimate === null
                || $opportunityInput->profit_estimate_id
                    !== $profitEstimate->getKey()
            )
        ) {
            $opportunityInput = null;
        }

        $logisticsAssessment = $this->relationLoaded(
            'currentLogisticsAssessment',
        )
            ? $this->getRelation('currentLogisticsAssessment')
            : null;
        $demandAssessment = $this->relationLoaded('currentDemandAssessment')
            ? $this->getRelation('currentDemandAssessment')
            : null;

        if (
            $logisticsAssessment !== null
            && (
                $opportunityInput === null
                || $logisticsAssessment->opportunity_input_id
                    !== $opportunityInput->getKey()
            )
        ) {
            $logisticsAssessment = null;
        }

        if (
            $demandAssessment !== null
            && (
                $opportunityInput === null
                || $demandAssessment->opportunity_input_id
                    !== $opportunityInput->getKey()
            )
        ) {
            $demandAssessment = null;
        }

        $dealScore = $this->relationLoaded('currentDealScore')
            ? $this->getRelation('currentDealScore')
            : null;

        if (
            $dealScore !== null
            && (
                $productMatch === null
                || $priceEstimate === null
                || $riskAssessment === null
                || $profitEstimate === null
                || $opportunityInput === null
                || $logisticsAssessment === null
                || $demandAssessment === null
                || $dealScore->product_match_id !== $productMatch->getKey()
                || $dealScore->price_estimate_id !== $priceEstimate->getKey()
                || $dealScore->risk_assessment_id
                    !== $riskAssessment->getKey()
                || $dealScore->profit_estimate_id
                    !== $profitEstimate->getKey()
                || $dealScore->opportunity_input_id
                    !== $opportunityInput->getKey()
                || $dealScore->logistics_assessment_id
                    !== $logisticsAssessment->getKey()
                || $dealScore->demand_assessment_id
                    !== $demandAssessment->getKey()
            )
        ) {
            $dealScore = null;
        }

        $buyerDecision = $this->relationLoaded(
            'currentBuyerDecisionEvent',
        )
            ? $this->getRelation('currentBuyerDecisionEvent')
            : null;

        if (
            $buyerDecision !== null
            && (
                $dealScore === null
                || $buyerDecision->deal_score_id !== $dealScore->getKey()
            )
        ) {
            $buyerDecision = null;
        }

        $allowedBuyerDecisions = (
            $dealScore !== null
            && $dealScore->status === DealScoreStatus::Assessed
            && $dealScore->score !== null
        )
            ? array_map(
                static fn (BuyerDecisionState $state): string => (
                    $state->value
                ),
                BuyerDecisionState::allowedFrom(
                    $buyerDecision?->next_state,
                ),
            )
            : [];

        return [
            ...parent::toArray($request),
            'listing_snapshot_id' => $this->listing_snapshot_id,
            'request_hash' => $this->request_hash,
            'request_payload' => $this->request_payload,
            'result_payload' => $this->result_payload,
            'last_error_message' => $this->last_error_message,
            'current_dispatch' => new AnalysisDispatchResource(
                $this->whenLoaded('currentDispatch'),
            ),
            'attempts' => AiAnalysisResource::collection(
                $this->whenLoaded('aiAnalyses'),
            ),
            'product_match' => new ProductMatchResource(
                $this->whenLoaded('currentProductMatch'),
            ),
            'comparable_set' => new ComparableSetResource(
                $this->whenLoaded('currentComparableSet'),
            ),
            'price_estimate' => new PriceEstimateResource(
                $this->whenLoaded('currentPriceEstimate'),
            ),
            'risk_assessment' => new RiskAssessmentResource(
                $this->whenLoaded('currentRiskAssessment'),
            ),
            'cost_input' => $this->when(
                $this->relationLoaded('currentCostInput'),
                fn () => new CostInputResource($costInput),
            ),
            'profit_estimate' => $this->when(
                $this->relationLoaded('currentProfitEstimate'),
                fn () => new ProfitEstimateResource($profitEstimate),
            ),
            'opportunity_input' => $this->when(
                $this->relationLoaded('currentOpportunityInput'),
                fn () => new OpportunityInputResource($opportunityInput),
            ),
            'logistics_assessment' => $this->when(
                $this->relationLoaded('currentLogisticsAssessment'),
                fn () => new OpportunityAssessmentResource(
                    $logisticsAssessment,
                ),
            ),
            'demand_assessment' => $this->when(
                $this->relationLoaded('currentDemandAssessment'),
                fn () => new OpportunityAssessmentResource($demandAssessment),
            ),
            'deal_score' => $this->when(
                $this->relationLoaded('currentDealScore'),
                fn () => new DealScoreResource($dealScore),
            ),
            'buyer_decision' => $this->when(
                $this->relationLoaded('currentBuyerDecisionEvent'),
                fn () => new BuyerDecisionEventResource($buyerDecision),
            ),
            'buyer_decision_allowed_transitions' => $this->when(
                $this->relationLoaded('currentBuyerDecisionEvent'),
                $allowedBuyerDecisions,
            ),
            'buyer_decision_history' => BuyerDecisionEventResource::collection(
                $this->whenLoaded('buyerDecisionEvents'),
            ),
            'buyer_decision_history_count' => $this->whenCounted(
                'buyerDecisionEvents',
            ),
        ];
    }
}
