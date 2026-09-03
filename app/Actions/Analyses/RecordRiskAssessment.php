<?php

namespace App\Actions\Analyses;

use App\Models\Analysis;
use App\Models\ComparableSet;
use App\Models\PriceEstimate;
use App\Models\ProductMatch;
use App\Models\RiskAssessment;
use App\RiskAssessment\Data\RiskAssessmentData;
use App\RiskAssessment\Data\RiskSignalData;
use Illuminate\Support\Facades\DB;
use LogicException;

class RecordRiskAssessment
{
    public function record(
        string $analysisId,
        string $productMatchId,
        string $comparableSetId,
        string $priceEstimateId,
        RiskAssessmentData $result,
    ): RiskAssessment {
        return DB::transaction(function () use (
            $analysisId,
            $productMatchId,
            $comparableSetId,
            $priceEstimateId,
            $result,
        ): RiskAssessment {
            $analysis = Analysis::query()->lockForUpdate()->findOrFail($analysisId);
            $productMatch = ProductMatch::query()
                ->lockForUpdate()
                ->findOrFail($productMatchId);
            $comparableSet = ComparableSet::query()
                ->lockForUpdate()
                ->findOrFail($comparableSetId);
            $priceEstimate = PriceEstimate::query()
                ->lockForUpdate()
                ->findOrFail($priceEstimateId);

            if (
                $productMatch->analysis_id !== $analysis->getKey()
                || $comparableSet->analysis_id !== $analysis->getKey()
                || $comparableSet->product_match_id !== $productMatch->getKey()
                || $priceEstimate->analysis_id !== $analysis->getKey()
                || $priceEstimate->comparable_set_id !== $comparableSet->getKey()
                || $productMatch->organization_id !== $analysis->organization_id
                || $comparableSet->organization_id !== $analysis->organization_id
                || $priceEstimate->organization_id !== $analysis->organization_id
            ) {
                throw new LogicException(
                    'Risk inputs do not belong to one immutable analysis evidence chain.',
                );
            }

            $assessmentKey = hash('sha256', implode('|', [
                $analysis->getKey(),
                $productMatch->getKey(),
                $comparableSet->getKey(),
                $priceEstimate->getKey(),
                $result->evaluatorVersion,
                $result->inputHash,
            ]));
            $existing = RiskAssessment::query()
                ->where('assessment_key', $assessmentKey)
                ->first();

            if ($existing !== null) {
                return $existing->load('signals');
            }

            $runNumber = ((int) RiskAssessment::query()
                ->where('analysis_id', $analysis->getKey())
                ->max('run_number')) + 1;
            $unknownCount = count(array_filter(
                $result->signals,
                static fn (RiskSignalData $signal): bool => $signal->isUnknown,
            ));
            $assessment = RiskAssessment::query()->create([
                'organization_id' => $analysis->organization_id,
                'analysis_id' => $analysis->getKey(),
                'product_match_id' => $productMatch->getKey(),
                'comparable_set_id' => $comparableSet->getKey(),
                'price_estimate_id' => $priceEstimate->getKey(),
                'run_number' => $runNumber,
                'status' => $result->status,
                'evaluator_version' => $result->evaluatorVersion,
                'input_hash' => $result->inputHash,
                'assessment_key' => $assessmentKey,
                'calculation_at' => $result->calculationAt,
                'score' => $result->score,
                'level' => $result->level,
                'confidence_basis_points' => $result->confidenceBasisPoints,
                'confidence_level' => $result->confidenceLevel,
                'signal_count' => count($result->signals),
                'unknown_count' => $unknownCount,
                'reason_codes' => $result->reasonCodes,
                'confidence_components' => $result->confidenceComponents,
                'verification_actions' => $result->verificationActions,
                'input_snapshot' => $result->inputSnapshot,
            ]);
            $assessment->signals()->createMany(array_map(
                static fn (
                    RiskSignalData $signal,
                    int $index,
                ): array => [
                    ...$signal->toArray(),
                    'position' => $index + 1,
                ],
                $result->signals,
                array_keys($result->signals),
            ));

            return $assessment->load('signals');
        }, attempts: 3);
    }
}
