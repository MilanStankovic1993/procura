<?php

namespace App\Actions\Analyses;

use App\DealScoring\Data\DealScoreData;
use App\Enums\Opportunity\OpportunityComponent;
use App\Jobs\Monitoring\MatchListingSnapshot;
use App\Models\Analysis;
use App\Models\DealScore;
use App\Models\OpportunityAssessment;
use App\Models\OpportunityInput;
use App\Models\PriceEstimate;
use App\Models\ProductMatch;
use App\Models\ProfitEstimate;
use App\Models\RiskAssessment;
use Illuminate\Support\Facades\DB;
use LogicException;

class RecordDealScore
{
    /** @return array{score: DealScore, created: bool} */
    public function record(
        Analysis $analysis,
        ProductMatch $productMatch,
        ProfitEstimate $profitEstimate,
        OpportunityAssessment $logistics,
        OpportunityAssessment $demand,
        DealScoreData $result,
    ): array {
        return DB::transaction(function () use (
            $analysis,
            $productMatch,
            $profitEstimate,
            $logistics,
            $demand,
            $result,
        ): array {
            $lockedAnalysis = Analysis::query()
                ->lockForUpdate()
                ->findOrFail($analysis->getKey());
            $lockedProductMatch = ProductMatch::query()
                ->lockForUpdate()
                ->findOrFail($productMatch->getKey());
            $lockedPrice = PriceEstimate::query()
                ->lockForUpdate()
                ->findOrFail($profitEstimate->price_estimate_id);
            $lockedRisk = RiskAssessment::query()
                ->lockForUpdate()
                ->findOrFail($profitEstimate->risk_assessment_id);
            $lockedProfit = ProfitEstimate::query()
                ->lockForUpdate()
                ->findOrFail($profitEstimate->getKey());
            $lockedOpportunityInput = OpportunityInput::query()
                ->lockForUpdate()
                ->findOrFail($logistics->opportunity_input_id);
            $lockedLogistics = OpportunityAssessment::query()
                ->lockForUpdate()
                ->findOrFail($logistics->getKey());
            $lockedDemand = OpportunityAssessment::query()
                ->lockForUpdate()
                ->findOrFail($demand->getKey());

            $this->guardChain(
                $lockedAnalysis,
                $lockedProductMatch,
                $lockedPrice,
                $lockedProfit,
                $lockedRisk,
                $lockedOpportunityInput,
                $lockedLogistics,
                $lockedDemand,
            );

            $scoreKey = hash('sha256', implode('|', [
                $lockedAnalysis->getKey(),
                $lockedProductMatch->getKey(),
                $lockedProfit->getKey(),
                $lockedLogistics->getKey(),
                $lockedDemand->getKey(),
                $result->calculationVersion,
                $result->inputHash,
            ]));
            $existing = DealScore::query()
                ->where('score_key', $scoreKey)
                ->first();

            if ($existing !== null) {
                return [
                    'score' => $existing->load('items'),
                    'created' => false,
                ];
            }

            $runNumber = ((int) DealScore::query()
                ->where('analysis_id', $lockedAnalysis->getKey())
                ->max('run_number')) + 1;
            $score = DealScore::query()->create([
                'organization_id' => $lockedAnalysis->organization_id,
                'analysis_id' => $lockedAnalysis->getKey(),
                'product_match_id' => $lockedProductMatch->getKey(),
                'price_estimate_id' => $lockedProfit->price_estimate_id,
                'risk_assessment_id' => $lockedProfit->risk_assessment_id,
                'profit_estimate_id' => $lockedProfit->getKey(),
                'opportunity_input_id' => (
                    $lockedLogistics->opportunity_input_id
                ),
                'logistics_assessment_id' => $lockedLogistics->getKey(),
                'demand_assessment_id' => $lockedDemand->getKey(),
                'run_number' => $runNumber,
                'status' => $result->status,
                'calculation_version' => $result->calculationVersion,
                'input_hash' => $result->inputHash,
                'score_key' => $scoreKey,
                'calculated_at' => $result->calculatedAt,
                'uncapped_score' => $result->uncappedScore,
                'uncapped_score_basis_points' => (
                    $result->uncappedScoreBasisPoints
                ),
                'score' => $result->score,
                'score_basis_points' => $result->scoreBasisPoints,
                'recommendation' => $result->recommendation,
                'confidence_basis_points' => (
                    $result->confidenceBasisPoints
                ),
                'confidence_level' => $result->confidenceLevel,
                'unknown_count' => $result->unknownCount,
                'applicable_cap' => $result->applicableCap,
                'cap_decisions' => $result->capDecisions,
                'reason_codes' => $result->reasonCodes,
                'confidence_components' => (
                    $result->confidenceComponents
                ),
                'factors_increasing' => $result->factorsIncreasing,
                'factors_reducing' => $result->factorsReducing,
                'assumptions' => $result->assumptions,
                'verification_actions' => (
                    $result->verificationActions
                ),
                'input_snapshot' => $result->inputSnapshot,
            ]);
            $score->items()->createMany($result->items);
            DB::afterCommit(
                static fn () => MatchListingSnapshot::dispatch(
                    $lockedAnalysis->listing_snapshot_id,
                ),
            );

            return [
                'score' => $score->load('items'),
                'created' => true,
            ];
        }, attempts: 3);
    }

    private function guardChain(
        Analysis $analysis,
        ProductMatch $productMatch,
        PriceEstimate $price,
        ProfitEstimate $profit,
        RiskAssessment $risk,
        OpportunityInput $opportunityInput,
        OpportunityAssessment $logistics,
        OpportunityAssessment $demand,
    ): void {
        if (
            $productMatch->analysis_id !== $analysis->getKey()
            || $price->analysis_id !== $analysis->getKey()
            || $profit->analysis_id !== $analysis->getKey()
            || $risk->analysis_id !== $analysis->getKey()
            || $opportunityInput->analysis_id !== $analysis->getKey()
            || $logistics->analysis_id !== $analysis->getKey()
            || $demand->analysis_id !== $analysis->getKey()
            || $risk->price_estimate_id !== $price->getKey()
            || $profit->price_estimate_id !== $price->getKey()
            || $profit->risk_assessment_id !== $risk->getKey()
            || $opportunityInput->comparable_set_id
                !== $price->comparable_set_id
            || $opportunityInput->price_estimate_id !== $price->getKey()
            || $opportunityInput->risk_assessment_id !== $risk->getKey()
            || $opportunityInput->cost_input_id !== $profit->cost_input_id
            || $opportunityInput->profit_estimate_id !== $profit->getKey()
            || $logistics->comparable_set_id
                !== $opportunityInput->comparable_set_id
            || $demand->comparable_set_id
                !== $opportunityInput->comparable_set_id
            || $logistics->price_estimate_id !== $price->getKey()
            || $demand->price_estimate_id !== $price->getKey()
            || $logistics->risk_assessment_id !== $risk->getKey()
            || $demand->risk_assessment_id !== $risk->getKey()
            || $logistics->cost_input_id !== $profit->cost_input_id
            || $demand->cost_input_id !== $profit->cost_input_id
            || $logistics->profit_estimate_id !== $profit->getKey()
            || $demand->profit_estimate_id !== $profit->getKey()
            || $logistics->opportunity_input_id
                !== $opportunityInput->getKey()
            || $demand->opportunity_input_id
                !== $opportunityInput->getKey()
            || $logistics->component !== OpportunityComponent::Logistics
            || $demand->component !== OpportunityComponent::Demand
            || $productMatch->getKey()
                !== $risk->product_match_id
            || collect([
                $productMatch,
                $price,
                $profit,
                $risk,
                $opportunityInput,
                $logistics,
                $demand,
            ])->contains(
                static fn ($record): bool => (
                    $record->organization_id !== $analysis->organization_id
                ),
            )
        ) {
            throw new LogicException(
                'DealScore requires one exact immutable evidence chain.',
            );
        }
    }
}
