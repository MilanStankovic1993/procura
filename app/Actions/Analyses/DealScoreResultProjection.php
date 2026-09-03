<?php

namespace App\Actions\Analyses;

use App\Enums\Analyses\AnalysisStatus;
use App\Enums\DealScoring\DealScoreStatus;
use App\Models\Analysis;
use App\Models\DealScore;
use Illuminate\Support\Facades\DB;
use LogicException;

class DealScoreResultProjection
{
    /** @return list<string> */
    public static function needsInputCodes(): array
    {
        return [
            'deal_score_estimated_net_margin_missing',
            'deal_score_price_confidence_missing',
            'deal_score_resale_demand_missing',
            'deal_score_inverse_risk_missing',
            'deal_score_logistics_simplicity_missing',
        ];
    }

    /**
     * @param  array<string, mixed>  $resultPayload
     * @return array<string, mixed>
     */
    public function project(
        array $resultPayload,
        DealScore $score,
    ): array {
        $needsInput = array_values(array_diff(
            $resultPayload['needs_input'] ?? [],
            self::needsInputCodes(),
        ));

        if ($score->status === DealScoreStatus::NeedsInput) {
            $needsInput = [
                ...$needsInput,
                ...array_values(array_intersect(
                    $score->reason_codes,
                    self::needsInputCodes(),
                )),
            ];
        }

        return [
            ...$resultPayload,
            'schema_version' => 'buy-analysis-extraction-result:v8',
            'needs_input' => array_values(array_unique($needsInput)),
            'completed_steps' => array_values(array_unique([
                ...($resultPayload['completed_steps'] ?? []),
                'deal_score',
            ])),
            'pending_steps' => array_values(array_diff(
                $resultPayload['pending_steps'] ?? [],
                ['deal_score'],
            )),
            'deal_score' => [
                'id' => $score->getKey(),
                'run_number' => $score->run_number,
                'status' => $score->status->value,
                'calculation_version' => $score->calculation_version,
                'input_hash' => $score->input_hash,
                'score' => $score->score,
                'score_basis_points' => $score->score_basis_points,
                'recommendation' => $score->recommendation->value,
                'confidence_basis_points' => (
                    $score->confidence_basis_points
                ),
                'applicable_cap' => $score->applicable_cap,
            ],
        ];
    }

    public function apply(Analysis $analysis, DealScore $score): Analysis
    {
        return DB::transaction(function () use (
            $analysis,
            $score,
        ): Analysis {
            $lockedAnalysis = Analysis::query()
                ->lockForUpdate()
                ->findOrFail($analysis->getKey());
            $lockedScore = DealScore::query()
                ->lockForUpdate()
                ->findOrFail($score->getKey());

            if (
                $lockedScore->analysis_id !== $lockedAnalysis->getKey()
                || $lockedScore->organization_id
                    !== $lockedAnalysis->organization_id
            ) {
                throw new LogicException(
                    'DealScore does not belong to the requested analysis.',
                );
            }

            if (! is_array($lockedAnalysis->result_payload)) {
                throw new LogicException(
                    'DealScore cannot be projected before analysis extraction.',
                );
            }

            if (
                ($lockedAnalysis->result_payload['deal_score']['id'] ?? null)
                    === $lockedScore->getKey()
            ) {
                return $lockedAnalysis;
            }

            $payload = $this->project(
                $lockedAnalysis->result_payload,
                $lockedScore,
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
