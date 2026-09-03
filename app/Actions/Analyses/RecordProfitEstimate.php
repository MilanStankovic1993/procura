<?php

namespace App\Actions\Analyses;

use App\Jobs\Monitoring\MatchListingSnapshot;
use App\Models\Analysis;
use App\Models\CostInput;
use App\Models\PriceEstimate;
use App\Models\ProfitEstimate;
use App\Models\RiskAssessment;
use App\ProfitCalculation\Data\ProfitEstimateData;
use Illuminate\Support\Facades\DB;
use LogicException;

class RecordProfitEstimate
{
    /**
     * @return array{estimate: ProfitEstimate, created: bool}
     */
    public function record(
        Analysis $analysis,
        PriceEstimate $priceEstimate,
        RiskAssessment $riskAssessment,
        CostInput $costInput,
        ProfitEstimateData $result,
    ): array {
        return DB::transaction(function () use (
            $analysis,
            $priceEstimate,
            $riskAssessment,
            $costInput,
            $result,
        ): array {
            $lockedAnalysis = Analysis::query()
                ->lockForUpdate()
                ->findOrFail($analysis->getKey());
            $lockedPriceEstimate = PriceEstimate::query()
                ->lockForUpdate()
                ->findOrFail($priceEstimate->getKey());
            $lockedRiskAssessment = RiskAssessment::query()
                ->lockForUpdate()
                ->findOrFail($riskAssessment->getKey());
            $lockedCostInput = CostInput::query()
                ->lockForUpdate()
                ->findOrFail($costInput->getKey());

            if (
                $lockedPriceEstimate->analysis_id !== $lockedAnalysis->getKey()
                || $lockedRiskAssessment->analysis_id !== $lockedAnalysis->getKey()
                || $lockedRiskAssessment->price_estimate_id
                    !== $lockedPriceEstimate->getKey()
                || $lockedCostInput->analysis_id !== $lockedAnalysis->getKey()
                || $lockedCostInput->price_estimate_id
                    !== $lockedPriceEstimate->getKey()
                || $lockedCostInput->risk_assessment_id
                    !== $lockedRiskAssessment->getKey()
                || $lockedPriceEstimate->organization_id
                    !== $lockedAnalysis->organization_id
                || $lockedRiskAssessment->organization_id
                    !== $lockedAnalysis->organization_id
                || $lockedCostInput->organization_id
                    !== $lockedAnalysis->organization_id
            ) {
                throw new LogicException(
                    'Profit estimates require one immutable analysis evidence chain.',
                );
            }

            $estimateKey = hash('sha256', implode('|', [
                $lockedAnalysis->getKey(),
                $lockedPriceEstimate->getKey(),
                $lockedRiskAssessment->getKey(),
                $lockedCostInput->getKey(),
                $result->calculationVersion,
                $result->inputHash,
            ]));
            $existing = ProfitEstimate::query()
                ->where('estimate_key', $estimateKey)
                ->first();

            if ($existing !== null) {
                return [
                    'estimate' => $existing->load('items'),
                    'created' => false,
                ];
            }

            $runNumber = ((int) ProfitEstimate::query()
                ->where('analysis_id', $lockedAnalysis->getKey())
                ->max('run_number')) + 1;
            $estimate = ProfitEstimate::query()->create([
                'organization_id' => $lockedAnalysis->organization_id,
                'analysis_id' => $lockedAnalysis->getKey(),
                'price_estimate_id' => $lockedPriceEstimate->getKey(),
                'risk_assessment_id' => $lockedRiskAssessment->getKey(),
                'cost_input_id' => $lockedCostInput->getKey(),
                'run_number' => $runNumber,
                'status' => $result->status,
                'calculation_version' => $result->calculationVersion,
                'input_hash' => $result->inputHash,
                'estimate_key' => $estimateKey,
                'calculation_at' => $result->calculationAt,
                'currency_code' => $result->currencyCode,
                'expected_sale_price_minor' => $result->expectedSalePriceMinor,
                'purchase_price_minor' => $result->purchasePriceMinor,
                'gross_margin_minor' => $result->grossMarginMinor,
                'known_costs_minor' => $result->knownCostsMinor,
                'additional_costs_minor' => $result->additionalCostsMinor,
                'total_cost_minor' => $result->totalCostMinor,
                'expected_net_profit_minor' => $result->expectedNetProfitMinor,
                'profit_margin_basis_points' => (
                    $result->profitMarginBasisPoints
                ),
                'return_on_invested_capital_basis_points' => (
                    $result->returnOnInvestedCapitalBasisPoints
                ),
                'confidence_basis_points' => $result->confidenceBasisPoints,
                'confidence_level' => $result->confidenceLevel,
                'unknown_count' => $result->unknownCount,
                'reason_codes' => $result->reasonCodes,
                'confidence_components' => $result->confidenceComponents,
                'input_snapshot' => $result->inputSnapshot,
            ]);
            $estimate->items()->createMany($result->items);
            DB::afterCommit(
                static fn () => MatchListingSnapshot::dispatch(
                    $lockedAnalysis->listing_snapshot_id,
                ),
            );

            return [
                'estimate' => $estimate->load('items'),
                'created' => true,
            ];
        }, attempts: 3);
    }
}
