<?php

namespace App\Actions\Analyses;

use App\Models\Analysis;
use App\Models\ComparableSet;
use App\Models\PriceEstimate;
use App\Pricing\Data\PriceEstimateData;
use Illuminate\Support\Facades\DB;
use LogicException;

class RecordPriceEstimate
{
    public function record(
        string $analysisId,
        string $comparableSetId,
        PriceEstimateData $result,
    ): PriceEstimate {
        return DB::transaction(function () use (
            $analysisId,
            $comparableSetId,
            $result,
        ): PriceEstimate {
            $analysis = Analysis::query()->lockForUpdate()->findOrFail($analysisId);
            $comparableSet = ComparableSet::query()
                ->lockForUpdate()
                ->findOrFail($comparableSetId);

            if (
                $comparableSet->analysis_id !== $analysis->getKey()
                || $comparableSet->organization_id !== $analysis->organization_id
            ) {
                throw new LogicException(
                    'The comparable set does not belong to the requested price estimate.',
                );
            }

            $estimateKey = hash('sha256', implode('|', [
                $analysis->getKey(),
                $comparableSet->getKey(),
                $result->algorithmVersion,
                $result->rateResolverVersion,
                $result->inputHash,
            ]));
            $existing = PriceEstimate::query()
                ->where('estimate_key', $estimateKey)
                ->first();

            if ($existing !== null) {
                return $existing->load('items');
            }

            $runNumber = ((int) PriceEstimate::query()
                ->where('analysis_id', $analysis->getKey())
                ->max('run_number')) + 1;
            $estimate = PriceEstimate::query()->create([
                'organization_id' => $analysis->organization_id,
                'analysis_id' => $analysis->getKey(),
                'comparable_set_id' => $comparableSet->getKey(),
                'run_number' => $runNumber,
                'status' => $result->status,
                'algorithm_version' => $result->algorithmVersion,
                'rate_resolver_version' => $result->rateResolverVersion,
                'input_hash' => $result->inputHash,
                'estimate_key' => $estimateKey,
                'calculation_at' => $result->calculationAt,
                'target_country_code' => $result->targetCountryCode,
                'target_currency_code' => $result->targetCurrencyCode,
                'input_count' => $result->inputCount,
                'included_count' => $result->includedCount,
                'outlier_count' => $result->outlierCount,
                'unresolved_count' => $result->unresolvedCount,
                'estimate_low_minor' => $result->estimateLowMinor,
                'estimate_minor' => $result->estimateMinor,
                'estimate_high_minor' => $result->estimateHighMinor,
                'median_minor' => $result->medianMinor,
                'weighted_median_minor' => $result->weightedMedianMinor,
                'q1_minor' => $result->q1Minor,
                'q3_minor' => $result->q3Minor,
                'mad_minor' => $result->madMinor,
                'dispersion_basis_points' => $result->dispersionBasisPoints,
                'confidence_basis_points' => $result->confidenceBasisPoints,
                'confidence_level' => $result->confidenceLevel,
                'reason_codes' => $result->reasonCodes,
                'confidence_components' => $result->confidenceComponents,
                'input_snapshot' => $result->inputSnapshot,
            ]);
            $estimate->items()->createMany($result->items);

            return $estimate->load('items');
        }, attempts: 3);
    }
}
