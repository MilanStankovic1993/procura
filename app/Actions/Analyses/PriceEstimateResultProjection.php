<?php

namespace App\Actions\Analyses;

use App\Enums\Analyses\AnalysisStatus;
use App\Enums\Pricing\PriceEstimateStatus;
use App\Models\Analysis;
use App\Models\PriceEstimate;
use Illuminate\Support\Facades\DB;
use LogicException;

class PriceEstimateResultProjection
{
    private const NEEDS_INPUT_CODES = [
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
    public function project(array $resultPayload, PriceEstimate $estimate): array
    {
        $needsInput = array_values(array_diff(
            $resultPayload['needs_input'] ?? [],
            [
                ...self::NEEDS_INPUT_CODES,
                ...ProfitEstimateResultProjection::needsInputCodes(),
                ...OpportunityAssessmentResultProjection::needsInputCodes(),
                ...DealScoreResultProjection::needsInputCodes(),
            ],
        ));

        if ($estimate->status === PriceEstimateStatus::NeedsInput) {
            $needsInput = [
                ...$needsInput,
                ...array_values(array_intersect(
                    $estimate->reason_codes,
                    self::NEEDS_INPUT_CODES,
                )),
            ];
        }

        $completedSteps = array_values(array_unique([
            ...array_values(array_diff(
                $resultPayload['completed_steps'] ?? [],
                [
                    'risk_assessment',
                    'cost_confirmation',
                    'profit_calculation',
                    'opportunity_evidence_confirmation',
                    'logistics_assessment',
                    'demand_assessment',
                    'deal_score',
                ],
            )),
            'price_estimation',
        ]));
        $pendingSteps = array_values(array_unique([
            ...array_values(array_diff(
                $resultPayload['pending_steps'] ?? [],
                ['price_estimation'],
            )),
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
            'risk_assessment' => null,
            'cost_input' => null,
            'profit_estimate' => null,
            'opportunity_input' => null,
            'logistics_assessment' => null,
            'demand_assessment' => null,
            'deal_score' => null,
            'price_estimate' => [
                'id' => $estimate->getKey(),
                'run_number' => $estimate->run_number,
                'status' => $estimate->status->value,
                'algorithm_version' => $estimate->algorithm_version,
                'rate_resolver_version' => $estimate->rate_resolver_version,
                'input_hash' => $estimate->input_hash,
                'calculation_at' => $estimate->calculation_at?->toIso8601String(),
                'target_country_code' => $estimate->target_country_code,
                'target_currency_code' => $estimate->target_currency_code,
                'input_count' => $estimate->input_count,
                'included_count' => $estimate->included_count,
                'outlier_count' => $estimate->outlier_count,
                'unresolved_count' => $estimate->unresolved_count,
                'estimate_low_minor' => $estimate->estimate_low_minor,
                'estimate_minor' => $estimate->estimate_minor,
                'estimate_high_minor' => $estimate->estimate_high_minor,
                'confidence_basis_points' => $estimate->confidence_basis_points,
                'confidence_level' => $estimate->confidence_level?->value,
                'reason_codes' => $estimate->reason_codes,
            ],
        ];
    }

    public function apply(Analysis $analysis, PriceEstimate $estimate): Analysis
    {
        return DB::transaction(function () use ($analysis, $estimate): Analysis {
            $lockedAnalysis = Analysis::query()
                ->lockForUpdate()
                ->findOrFail($analysis->getKey());
            $lockedEstimate = PriceEstimate::query()
                ->lockForUpdate()
                ->findOrFail($estimate->getKey());

            if (
                $lockedEstimate->analysis_id !== $lockedAnalysis->getKey()
                || $lockedEstimate->organization_id
                    !== $lockedAnalysis->organization_id
            ) {
                throw new LogicException(
                    'The price estimate does not belong to the requested analysis.',
                );
            }

            if (! is_array($lockedAnalysis->result_payload)) {
                throw new LogicException(
                    'Price estimation cannot be projected before analysis extraction.',
                );
            }

            if (
                ($lockedAnalysis->result_payload['price_estimate']['id'] ?? null)
                === $lockedEstimate->getKey()
            ) {
                return $lockedAnalysis;
            }

            $payload = $this->project(
                $lockedAnalysis->result_payload,
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
