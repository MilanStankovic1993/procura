<?php

namespace App\Actions\Analyses;

use App\Enums\Analyses\AnalysisStatus;
use App\Enums\Catalog\ProductMatchReviewDecision;
use App\Models\Analysis;
use App\Models\ProductMatch;
use Illuminate\Support\Facades\DB;
use LogicException;

final class ProductMatchReviewResultProjection
{
    private const MATCH_NEEDS_INPUT_CODES = [
        'model_uncertain',
        'model_confirmation_required',
        'region_incompatible_variant',
    ];

    public function apply(
        Analysis $analysis,
        ProductMatch $sourceMatch,
        ProductMatchReviewDecision $decision,
        ?ProductMatch $resultMatch,
    ): Analysis {
        return DB::transaction(function () use (
            $analysis,
            $sourceMatch,
            $decision,
            $resultMatch,
        ): Analysis {
            $lockedAnalysis = Analysis::query()
                ->lockForUpdate()
                ->findOrFail($analysis->getKey());
            $lockedSource = ProductMatch::query()
                ->lockForUpdate()
                ->findOrFail($sourceMatch->getKey());
            $lockedResult = $resultMatch === null
                ? null
                : ProductMatch::query()
                    ->lockForUpdate()
                    ->findOrFail($resultMatch->getKey());

            if (
                $lockedSource->analysis_id !== $lockedAnalysis->getKey()
                || $lockedSource->organization_id
                    !== $lockedAnalysis->organization_id
                || (
                    $lockedResult !== null
                    && (
                        $lockedResult->analysis_id !== $lockedAnalysis->getKey()
                        || $lockedResult->organization_id
                            !== $lockedAnalysis->organization_id
                    )
                )
            ) {
                throw new LogicException(
                    'Product match review evidence does not belong to the analysis.',
                );
            }

            if (! is_array($lockedAnalysis->result_payload)) {
                throw new LogicException(
                    'Product match review cannot be projected before analysis extraction.',
                );
            }

            $projectedMatch = $decision === ProductMatchReviewDecision::Confirm
                ? $lockedResult
                : $lockedSource;

            if ($projectedMatch === null) {
                throw new LogicException(
                    'A confirmed review requires a resulting product match.',
                );
            }

            $payload = $lockedAnalysis->result_payload;
            $needsInput = array_values(array_diff(
                $payload['needs_input'] ?? [],
                self::MATCH_NEEDS_INPUT_CODES,
            ));

            if ($decision === ProductMatchReviewDecision::Reject) {
                $needsInput[] = $lockedSource->status->value === 'unmatched'
                    ? 'model_uncertain'
                    : 'model_confirmation_required';
            }

            $payload['needs_input'] = array_values(array_unique($needsInput));
            $payload['product_match'] = [
                'id' => $projectedMatch->getKey(),
                'status' => $projectedMatch->status->value,
                'review_status' => $projectedMatch->review_status->value,
                'method' => $projectedMatch->method,
                'matcher_version' => $projectedMatch->matcher_version,
                'confidence_basis_points' => (
                    $projectedMatch->confidence_basis_points
                ),
                'product_model_id' => $projectedMatch->product_model_id,
                'product_variant_id' => $projectedMatch->product_variant_id,
                'reason_codes' => $projectedMatch->reason_codes,
            ];

            if ($decision === ProductMatchReviewDecision::Confirm) {
                $payload['completed_steps'] = array_values(array_unique([
                    ...($payload['completed_steps'] ?? []),
                    'product_matching',
                ]));
                $payload['pending_steps'] = array_values(array_unique([
                    ...array_values(array_diff(
                        $payload['pending_steps'] ?? [],
                        ['comparable_selection'],
                    )),
                    'comparable_selection',
                ]));
                $payload['comparable_set'] = null;
                $payload['price_estimate'] = null;
                $payload['risk_assessment'] = null;
                $payload['cost_input'] = null;
                $payload['profit_estimate'] = null;
                $payload['opportunity_input'] = null;
                $payload['logistics_assessment'] = null;
                $payload['demand_assessment'] = null;
                $payload['deal_score'] = null;
            }

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
