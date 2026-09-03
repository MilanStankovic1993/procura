<?php

namespace App\Actions\Analyses;

use App\Enums\Analyses\AnalysisStatus;
use App\Models\Analysis;
use App\Models\RiskAssessment;
use Illuminate\Support\Facades\DB;
use LogicException;

class RiskAssessmentResultProjection
{
    /**
     * @param  array<string, mixed>  $resultPayload
     * @return array<string, mixed>
     */
    public function project(
        array $resultPayload,
        RiskAssessment $assessment,
    ): array {
        $needsInput = array_values(array_diff(
            $resultPayload['needs_input'] ?? [],
            [
                ...ProfitEstimateResultProjection::needsInputCodes(),
                ...OpportunityAssessmentResultProjection::needsInputCodes(),
                ...DealScoreResultProjection::needsInputCodes(),
            ],
        ));
        $completedSteps = array_values(array_unique([
            ...array_values(array_diff(
                $resultPayload['completed_steps'] ?? [],
                [
                    'cost_confirmation',
                    'profit_calculation',
                    'opportunity_evidence_confirmation',
                    'logistics_assessment',
                    'demand_assessment',
                    'deal_score',
                ],
            )),
            'risk_assessment',
        ]));
        $pendingSteps = array_values(array_unique([
            ...array_values(array_diff(
                $resultPayload['pending_steps'] ?? [],
                ['risk_assessment'],
            )),
            'cost_confirmation',
            'profit_calculation',
            'opportunity_evidence_confirmation',
            'logistics_assessment',
            'demand_assessment',
            'deal_score',
        ]));

        return [
            ...$resultPayload,
            'schema_version' => 'buy-analysis-extraction-result:v8',
            'needs_input' => $needsInput,
            'completed_steps' => $completedSteps,
            'pending_steps' => $pendingSteps,
            'cost_input' => null,
            'profit_estimate' => null,
            'opportunity_input' => null,
            'logistics_assessment' => null,
            'demand_assessment' => null,
            'deal_score' => null,
            'risk_assessment' => [
                'id' => $assessment->getKey(),
                'run_number' => $assessment->run_number,
                'status' => $assessment->status->value,
                'evaluator_version' => $assessment->evaluator_version,
                'input_hash' => $assessment->input_hash,
                'calculation_at' => $assessment->calculation_at?->toIso8601String(),
                'score' => $assessment->score,
                'level' => $assessment->level->value,
                'confidence_basis_points' => $assessment->confidence_basis_points,
                'confidence_level' => $assessment->confidence_level->value,
                'signal_count' => $assessment->signal_count,
                'unknown_count' => $assessment->unknown_count,
                'reason_codes' => $assessment->reason_codes,
                'verification_actions' => $assessment->verification_actions,
            ],
        ];
    }

    public function apply(
        Analysis $analysis,
        RiskAssessment $assessment,
    ): Analysis {
        return DB::transaction(function () use (
            $analysis,
            $assessment,
        ): Analysis {
            $lockedAnalysis = Analysis::query()
                ->lockForUpdate()
                ->findOrFail($analysis->getKey());
            $lockedAssessment = RiskAssessment::query()
                ->lockForUpdate()
                ->findOrFail($assessment->getKey());

            if (
                $lockedAssessment->analysis_id !== $lockedAnalysis->getKey()
                || $lockedAssessment->organization_id
                    !== $lockedAnalysis->organization_id
            ) {
                throw new LogicException(
                    'The risk assessment does not belong to the requested analysis.',
                );
            }

            if (! is_array($lockedAnalysis->result_payload)) {
                throw new LogicException(
                    'Risk assessment cannot be projected before analysis extraction.',
                );
            }

            if (
                ($lockedAnalysis->result_payload['risk_assessment']['id'] ?? null)
                === $lockedAssessment->getKey()
            ) {
                return $lockedAnalysis;
            }

            $payload = $this->project(
                $lockedAnalysis->result_payload,
                $lockedAssessment,
            );
            $lockedAnalysis->update([
                'status' => ($payload['needs_input'] ?? []) === []
                    ? AnalysisStatus::Completed
                    : AnalysisStatus::NeedsInput,
                'result_payload' => $payload,
                'finished_at' => now(),
            ]);

            return $lockedAnalysis->fresh();
        }, attempts: 3);
    }
}
