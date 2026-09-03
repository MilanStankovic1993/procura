<?php

namespace App\Actions\Analyses;

use App\Enums\Analyses\AnalysisStatus;
use App\Enums\Opportunity\OpportunityAssessmentStatus;
use App\Enums\Opportunity\OpportunityComponent;
use App\Enums\Opportunity\OpportunityEvidenceCode;
use App\Models\Analysis;
use App\Models\OpportunityAssessment;
use App\Models\OpportunityInput;
use Illuminate\Support\Facades\DB;
use LogicException;

class OpportunityAssessmentResultProjection
{
    /** @return list<string> */
    public static function needsInputCodes(): array
    {
        return array_map(
            static fn (OpportunityEvidenceCode $code): string => (
                "opportunity_{$code->value}_unknown"
            ),
            OpportunityEvidenceCode::cases(),
        );
    }

    /**
     * @param  array<string, mixed>  $resultPayload
     * @return array<string, mixed>
     */
    public function project(
        array $resultPayload,
        OpportunityInput $input,
        OpportunityAssessment $logistics,
        OpportunityAssessment $demand,
    ): array {
        $needsInput = array_values(array_diff(
            $resultPayload['needs_input'] ?? [],
            [
                ...self::needsInputCodes(),
                ...DealScoreResultProjection::needsInputCodes(),
            ],
        ));

        foreach ([$logistics, $demand] as $assessment) {
            if ($assessment->status === OpportunityAssessmentStatus::NeedsInput) {
                $needsInput = [
                    ...$needsInput,
                    ...array_values(array_intersect(
                        $assessment->reason_codes,
                        self::needsInputCodes(),
                    )),
                ];
            }
        }

        $completedSteps = array_values(array_unique([
            ...($resultPayload['completed_steps'] ?? []),
            'opportunity_evidence_confirmation',
            'logistics_assessment',
            'demand_assessment',
        ]));
        $pendingSteps = array_values(array_diff(
            $resultPayload['pending_steps'] ?? [],
            [
                'opportunity_evidence_confirmation',
                'logistics_assessment',
                'demand_assessment',
            ],
        ));
        $pendingSteps = array_values(array_unique([
            ...$pendingSteps,
            'deal_score',
        ]));

        return [
            ...$resultPayload,
            'schema_version' => 'buy-analysis-extraction-result:v8',
            'needs_input' => array_values(array_unique($needsInput)),
            'completed_steps' => $completedSteps,
            'pending_steps' => $pendingSteps,
            'opportunity_input' => [
                'id' => $input->getKey(),
                'run_number' => $input->run_number,
                'input_version' => $input->input_version,
                'input_hash' => $input->input_hash,
                'known_count' => $input->known_count,
                'unknown_count' => $input->unknown_count,
            ],
            'logistics_assessment' => $this->summary($logistics),
            'demand_assessment' => $this->summary($demand),
            'deal_score' => null,
        ];
    }

    public function apply(
        Analysis $analysis,
        OpportunityInput $input,
        OpportunityAssessment $logistics,
        OpportunityAssessment $demand,
    ): Analysis {
        return DB::transaction(function () use (
            $analysis,
            $input,
            $logistics,
            $demand,
        ): Analysis {
            $lockedAnalysis = Analysis::query()
                ->lockForUpdate()
                ->findOrFail($analysis->getKey());
            $lockedInput = OpportunityInput::query()
                ->lockForUpdate()
                ->findOrFail($input->getKey());
            $lockedLogistics = OpportunityAssessment::query()
                ->lockForUpdate()
                ->findOrFail($logistics->getKey());
            $lockedDemand = OpportunityAssessment::query()
                ->lockForUpdate()
                ->findOrFail($demand->getKey());

            if (
                $lockedInput->analysis_id !== $lockedAnalysis->getKey()
                || $lockedLogistics->analysis_id
                    !== $lockedAnalysis->getKey()
                || $lockedDemand->analysis_id !== $lockedAnalysis->getKey()
                || $lockedLogistics->opportunity_input_id
                    !== $lockedInput->getKey()
                || $lockedDemand->opportunity_input_id
                    !== $lockedInput->getKey()
                || $lockedLogistics->component
                    !== OpportunityComponent::Logistics
                || $lockedDemand->component !== OpportunityComponent::Demand
                || $lockedInput->organization_id
                    !== $lockedAnalysis->organization_id
                || $lockedLogistics->organization_id
                    !== $lockedAnalysis->organization_id
                || $lockedDemand->organization_id
                    !== $lockedAnalysis->organization_id
            ) {
                throw new LogicException(
                    'Opportunity assessments do not belong to one analysis input.',
                );
            }

            if (! is_array($lockedAnalysis->result_payload)) {
                throw new LogicException(
                    'Opportunity assessment cannot be projected before analysis extraction.',
                );
            }

            if (
                ($lockedAnalysis->result_payload['logistics_assessment']['id']
                    ?? null) === $lockedLogistics->getKey()
                && ($lockedAnalysis->result_payload['demand_assessment']['id']
                    ?? null) === $lockedDemand->getKey()
            ) {
                return $lockedAnalysis;
            }

            $payload = $this->project(
                $lockedAnalysis->result_payload,
                $lockedInput,
                $lockedLogistics,
                $lockedDemand,
            );
            $lockedAnalysis->update([
                'status' => $payload['needs_input'] === []
                    ? AnalysisStatus::Completed
                    : AnalysisStatus::NeedsInput,
                'result_payload' => $payload,
                'finished_at' => now(),
            ]);

            return $lockedAnalysis->fresh();
        }, attempts: 3);
    }

    /** @return array<string, mixed> */
    private function summary(OpportunityAssessment $assessment): array
    {
        return [
            'id' => $assessment->getKey(),
            'run_number' => $assessment->run_number,
            'component' => $assessment->component->value,
            'status' => $assessment->status->value,
            'evaluator_version' => $assessment->evaluator_version,
            'input_hash' => $assessment->input_hash,
            'calculated_at' => $assessment->calculated_at?->toIso8601String(),
            'score' => $assessment->score,
            'confidence_basis_points' => $assessment->confidence_basis_points,
            'confidence_level' => $assessment->confidence_level->value,
            'unknown_count' => $assessment->unknown_count,
            'reason_codes' => $assessment->reason_codes,
        ];
    }
}
