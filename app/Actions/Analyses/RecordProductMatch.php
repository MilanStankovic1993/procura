<?php

namespace App\Actions\Analyses;

use App\Models\AiAnalysis;
use App\Models\Analysis;
use App\Models\ProductMatch;
use App\ProductMatching\Data\ProductMatchData;
use Illuminate\Support\Facades\DB;
use LogicException;

class RecordProductMatch
{
    public function record(
        string $analysisId,
        string $aiAnalysisId,
        ProductMatchData $result,
    ): ProductMatch {
        return DB::transaction(function () use (
            $analysisId,
            $aiAnalysisId,
            $result,
        ): ProductMatch {
            $analysis = Analysis::query()->lockForUpdate()->findOrFail($analysisId);
            $aiAnalysis = AiAnalysis::query()->lockForUpdate()->findOrFail($aiAnalysisId);

            if (
                $aiAnalysis->analysis_id !== $analysis->getKey()
                || $aiAnalysis->organization_id !== $analysis->organization_id
            ) {
                throw new LogicException(
                    'The AI attempt does not belong to the requested analysis.',
                );
            }

            $matchKey = hash('sha256', implode('|', [
                $analysis->getKey(),
                $aiAnalysis->getKey(),
                $result->matcherVersion,
            ]));
            $existing = ProductMatch::query()->where('match_key', $matchKey)->first();

            if ($existing !== null) {
                return $existing;
            }

            $runNumber = ((int) ProductMatch::query()
                ->where('analysis_id', $analysis->getKey())
                ->max('run_number')) + 1;

            return ProductMatch::query()->create([
                'organization_id' => $analysis->organization_id,
                'analysis_id' => $analysis->getKey(),
                'ai_analysis_id' => $aiAnalysis->getKey(),
                'product_model_id' => $result->productModelId,
                'product_variant_id' => $result->productVariantId,
                'run_number' => $runNumber,
                'status' => $result->status,
                'review_status' => $result->reviewStatus,
                'method' => $result->method,
                'matcher_version' => $result->matcherVersion,
                'input_hash' => $result->inputHash,
                'match_key' => $matchKey,
                'confidence_basis_points' => $result->confidenceBasisPoints,
                'candidate_snapshot' => $result->candidates,
                'reason_codes' => $result->reasonCodes,
            ]);
        }, attempts: 3);
    }
}
