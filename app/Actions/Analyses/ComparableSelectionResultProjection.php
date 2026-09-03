<?php

namespace App\Actions\Analyses;

use App\Enums\Analyses\AnalysisStatus;
use App\Enums\Comparables\ComparableSetStatus;
use App\Models\Analysis;
use App\Models\ComparableSet;
use Illuminate\Support\Facades\DB;
use LogicException;

class ComparableSelectionResultProjection
{
    private const PRICE_NEEDS_INPUT_CODES = [
        'price_conversion_evidence_incomplete',
        'exchange_rate_missing',
        'exchange_rate_stale',
        'currency_metadata_missing',
        'converted_amount_invalid',
        'normalized_amount_invalid',
        'market_normalization_evidence_invalid',
        'insufficient_price_evidence',
        'insufficient_price_evidence_after_outlier_removal',
    ];

    /**
     * @param  array<string, mixed>  $resultPayload
     * @return array<string, mixed>
     */
    public function project(array $resultPayload, ComparableSet $set): array
    {
        $needsInput = array_values(array_diff(
            $resultPayload['needs_input'] ?? [],
            [
                'no_comparable_records',
                'insufficient_comparable_records',
                ...self::PRICE_NEEDS_INPUT_CODES,
                ...ProfitEstimateResultProjection::needsInputCodes(),
                ...OpportunityAssessmentResultProjection::needsInputCodes(),
                ...DealScoreResultProjection::needsInputCodes(),
            ],
        ));

        if ($set->status === ComparableSetStatus::Insufficient) {
            $needsInput[] = $set->included_count === 0
                ? 'no_comparable_records'
                : 'insufficient_comparable_records';
        }

        $completedSteps = array_values(array_unique([
            ...array_values(array_diff(
                $resultPayload['completed_steps'] ?? [],
                [
                    'price_estimation',
                    'risk_assessment',
                    'cost_confirmation',
                    'profit_calculation',
                    'opportunity_evidence_confirmation',
                    'logistics_assessment',
                    'demand_assessment',
                    'deal_score',
                ],
            )),
            'comparable_selection',
        ]));
        $pendingSteps = array_values(array_unique([
            ...array_values(array_diff(
                $resultPayload['pending_steps'] ?? [],
                ['comparable_selection'],
            )),
            'price_estimation',
            'risk_assessment',
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
            'needs_input' => array_values(array_unique($needsInput)),
            'completed_steps' => $completedSteps,
            'pending_steps' => $pendingSteps,
            'price_estimate' => null,
            'risk_assessment' => null,
            'cost_input' => null,
            'profit_estimate' => null,
            'opportunity_input' => null,
            'logistics_assessment' => null,
            'demand_assessment' => null,
            'deal_score' => null,
            'comparable_set' => [
                'id' => $set->getKey(),
                'run_number' => $set->run_number,
                'status' => $set->status->value,
                'selector_version' => $set->selector_version,
                'input_hash' => $set->input_hash,
                'target_country_code' => $set->target_country_code,
                'target_currency_code' => $set->target_currency_code,
                'candidate_count' => $set->candidate_count,
                'included_count' => $set->included_count,
                'excluded_count' => $set->excluded_count,
                'minimum_required' => $set->minimum_required,
                'reason_codes' => $set->reason_codes,
            ],
        ];
    }

    public function apply(Analysis $analysis, ComparableSet $set): Analysis
    {
        return DB::transaction(function () use ($analysis, $set): Analysis {
            $lockedAnalysis = Analysis::query()
                ->lockForUpdate()
                ->findOrFail($analysis->getKey());
            $lockedSet = ComparableSet::query()
                ->lockForUpdate()
                ->findOrFail($set->getKey());

            if (
                $lockedSet->analysis_id !== $lockedAnalysis->getKey()
                || $lockedSet->organization_id !== $lockedAnalysis->organization_id
            ) {
                throw new LogicException(
                    'The comparable set does not belong to the requested analysis.',
                );
            }

            if (! is_array($lockedAnalysis->result_payload)) {
                throw new LogicException(
                    'Comparable selection cannot be projected before analysis extraction.',
                );
            }

            if (
                ($lockedAnalysis->result_payload['comparable_set']['id'] ?? null)
                === $lockedSet->getKey()
            ) {
                return $lockedAnalysis;
            }

            $payload = $this->project($lockedAnalysis->result_payload, $lockedSet);
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
