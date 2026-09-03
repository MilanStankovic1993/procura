<?php

namespace App\Actions\Analyses;

use App\Enums\Analyses\AnalysisStatus;
use App\Enums\Profit\CostCategory;
use App\Enums\Profit\ProfitEstimateStatus;
use App\Models\Analysis;
use App\Models\CostInput;
use App\Models\ProfitEstimate;
use Illuminate\Support\Facades\DB;
use LogicException;

class ProfitEstimateResultProjection
{
    /** @return list<string> */
    public static function needsInputCodes(): array
    {
        return [
            ...array_map(
                static fn (CostCategory $category): string => (
                    "cost_{$category->value}_unknown"
                ),
                CostCategory::cases(),
            ),
            'cross_border_transport_unknown',
            'cross_border_customs_unknown',
            'cross_border_tax_unknown',
            'regional_compatibility_unconfirmed',
            'profit_estimate_has_unknown_costs',
            'profit_ratio_out_of_bounds',
        ];
    }

    /**
     * @param  array<string, mixed>  $resultPayload
     * @return array<string, mixed>
     */
    public function project(
        array $resultPayload,
        CostInput $costInput,
        ProfitEstimate $estimate,
    ): array {
        $needsInput = array_values(array_diff(
            $resultPayload['needs_input'] ?? [],
            [
                ...self::needsInputCodes(),
                ...OpportunityAssessmentResultProjection::needsInputCodes(),
                ...DealScoreResultProjection::needsInputCodes(),
            ],
        ));

        if ($estimate->status === ProfitEstimateStatus::NeedsInput) {
            $needsInput = [
                ...$needsInput,
                ...array_values(array_intersect(
                    $estimate->reason_codes,
                    self::needsInputCodes(),
                )),
            ];
        }

        $completedSteps = array_values(array_unique([
            ...array_values(array_diff(
                $resultPayload['completed_steps'] ?? [],
                [
                    'opportunity_evidence_confirmation',
                    'logistics_assessment',
                    'demand_assessment',
                    'deal_score',
                ],
            )),
            'cost_confirmation',
            'profit_calculation',
        ]));
        $pendingSteps = array_values(array_unique([
            ...array_values(array_diff(
                $resultPayload['pending_steps'] ?? [],
                ['cost_confirmation', 'profit_calculation'],
            )),
            'opportunity_evidence_confirmation',
            'logistics_assessment',
            'demand_assessment',
            'deal_score',
        ]));

        return [
            ...$resultPayload,
            'schema_version' => 'buy-analysis-extraction-result:v8',
            'needs_input' => array_values(array_unique($needsInput)),
            'completed_steps' => $completedSteps,
            'pending_steps' => $pendingSteps,
            'opportunity_input' => null,
            'logistics_assessment' => null,
            'demand_assessment' => null,
            'deal_score' => null,
            'cost_input' => [
                'id' => $costInput->getKey(),
                'run_number' => $costInput->run_number,
                'input_version' => $costInput->input_version,
                'input_hash' => $costInput->input_hash,
                'currency_code' => $costInput->currency_code,
                'source_country_code' => $costInput->source_country_code,
                'target_country_code' => $costInput->target_country_code,
                'regional_compatibility_confirmed' => (
                    $costInput->regional_compatibility_confirmed
                ),
                'known_count' => $costInput->known_count,
                'unknown_count' => $costInput->unknown_count,
            ],
            'profit_estimate' => [
                'id' => $estimate->getKey(),
                'run_number' => $estimate->run_number,
                'status' => $estimate->status->value,
                'calculation_version' => $estimate->calculation_version,
                'input_hash' => $estimate->input_hash,
                'calculation_at' => $estimate->calculation_at?->toIso8601String(),
                'currency_code' => $estimate->currency_code,
                'expected_sale_price_minor' => $estimate->expected_sale_price_minor,
                'total_cost_minor' => $estimate->total_cost_minor,
                'expected_net_profit_minor' => (
                    $estimate->expected_net_profit_minor
                ),
                'profit_margin_basis_points' => (
                    $estimate->profit_margin_basis_points
                ),
                'return_on_invested_capital_basis_points' => (
                    $estimate->return_on_invested_capital_basis_points
                ),
                'confidence_basis_points' => $estimate->confidence_basis_points,
                'confidence_level' => $estimate->confidence_level->value,
                'unknown_count' => $estimate->unknown_count,
                'reason_codes' => $estimate->reason_codes,
            ],
        ];
    }

    public function apply(
        Analysis $analysis,
        CostInput $costInput,
        ProfitEstimate $estimate,
    ): Analysis {
        return DB::transaction(function () use (
            $analysis,
            $costInput,
            $estimate,
        ): Analysis {
            $lockedAnalysis = Analysis::query()
                ->lockForUpdate()
                ->findOrFail($analysis->getKey());
            $lockedCostInput = CostInput::query()
                ->lockForUpdate()
                ->findOrFail($costInput->getKey());
            $lockedEstimate = ProfitEstimate::query()
                ->lockForUpdate()
                ->findOrFail($estimate->getKey());

            if (
                $lockedCostInput->analysis_id !== $lockedAnalysis->getKey()
                || $lockedEstimate->analysis_id !== $lockedAnalysis->getKey()
                || $lockedEstimate->cost_input_id !== $lockedCostInput->getKey()
                || $lockedEstimate->price_estimate_id
                    !== $lockedCostInput->price_estimate_id
                || $lockedEstimate->risk_assessment_id
                    !== $lockedCostInput->risk_assessment_id
                || $lockedCostInput->organization_id
                    !== $lockedAnalysis->organization_id
                || $lockedEstimate->organization_id
                    !== $lockedAnalysis->organization_id
            ) {
                throw new LogicException(
                    'The profit estimate does not belong to the requested analysis.',
                );
            }

            if (! is_array($lockedAnalysis->result_payload)) {
                throw new LogicException(
                    'Profit calculation cannot be projected before analysis extraction.',
                );
            }

            if (
                ($lockedAnalysis->result_payload['profit_estimate']['id'] ?? null)
                === $lockedEstimate->getKey()
            ) {
                return $lockedAnalysis;
            }

            $payload = $this->project(
                $lockedAnalysis->result_payload,
                $lockedCostInput,
                $lockedEstimate,
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
}
