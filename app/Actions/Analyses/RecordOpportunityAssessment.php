<?php

namespace App\Actions\Analyses;

use App\Models\Analysis;
use App\Models\OpportunityAssessment;
use App\Models\OpportunityInput;
use App\Models\ProfitEstimate;
use App\OpportunityAssessment\Data\OpportunityAssessmentData;
use Illuminate\Support\Facades\DB;
use LogicException;

class RecordOpportunityAssessment
{
    /**
     * @return array{assessment: OpportunityAssessment, created: bool}
     */
    public function record(
        Analysis $analysis,
        ProfitEstimate $profitEstimate,
        OpportunityInput $input,
        OpportunityAssessmentData $result,
    ): array {
        return DB::transaction(function () use (
            $analysis,
            $profitEstimate,
            $input,
            $result,
        ): array {
            $lockedAnalysis = Analysis::query()
                ->lockForUpdate()
                ->findOrFail($analysis->getKey());
            $lockedProfitEstimate = ProfitEstimate::query()
                ->lockForUpdate()
                ->findOrFail($profitEstimate->getKey());
            $lockedInput = OpportunityInput::query()
                ->lockForUpdate()
                ->findOrFail($input->getKey());

            if (
                $lockedProfitEstimate->analysis_id
                    !== $lockedAnalysis->getKey()
                || $lockedInput->analysis_id !== $lockedAnalysis->getKey()
                || $lockedInput->profit_estimate_id
                    !== $lockedProfitEstimate->getKey()
                || $lockedInput->price_estimate_id
                    !== $lockedProfitEstimate->price_estimate_id
                || $lockedInput->risk_assessment_id
                    !== $lockedProfitEstimate->risk_assessment_id
                || $lockedInput->cost_input_id
                    !== $lockedProfitEstimate->cost_input_id
                || $lockedProfitEstimate->organization_id
                    !== $lockedAnalysis->organization_id
                || $lockedInput->organization_id
                    !== $lockedAnalysis->organization_id
            ) {
                throw new LogicException(
                    'Opportunity assessments require one immutable evidence chain.',
                );
            }

            $assessmentKey = hash('sha256', implode('|', [
                $lockedAnalysis->getKey(),
                $lockedInput->getKey(),
                $result->component->value,
                $result->evaluatorVersion,
                $result->inputHash,
            ]));
            $existing = OpportunityAssessment::query()
                ->where('assessment_key', $assessmentKey)
                ->first();

            if ($existing !== null) {
                return [
                    'assessment' => $existing->load('items'),
                    'created' => false,
                ];
            }

            $runNumber = ((int) OpportunityAssessment::query()
                ->where('analysis_id', $lockedAnalysis->getKey())
                ->where('component', $result->component)
                ->max('run_number')) + 1;
            $assessment = OpportunityAssessment::query()->create([
                'organization_id' => $lockedAnalysis->organization_id,
                'analysis_id' => $lockedAnalysis->getKey(),
                'comparable_set_id' => $lockedInput->comparable_set_id,
                'price_estimate_id' => $lockedInput->price_estimate_id,
                'risk_assessment_id' => $lockedInput->risk_assessment_id,
                'cost_input_id' => $lockedInput->cost_input_id,
                'profit_estimate_id' => $lockedInput->profit_estimate_id,
                'opportunity_input_id' => $lockedInput->getKey(),
                'component' => $result->component,
                'run_number' => $runNumber,
                'status' => $result->status,
                'evaluator_version' => $result->evaluatorVersion,
                'input_hash' => $result->inputHash,
                'assessment_key' => $assessmentKey,
                'calculated_at' => $result->calculatedAt,
                'score' => $result->score,
                'confidence_basis_points' => $result->confidenceBasisPoints,
                'confidence_level' => $result->confidenceLevel,
                'unknown_count' => $result->unknownCount,
                'reason_codes' => $result->reasonCodes,
                'confidence_components' => $result->confidenceComponents,
                'input_snapshot' => $result->inputSnapshot,
            ]);
            $assessment->items()->createMany($result->items);

            return [
                'assessment' => $assessment->load('items'),
                'created' => true,
            ];
        }, attempts: 3);
    }
}
