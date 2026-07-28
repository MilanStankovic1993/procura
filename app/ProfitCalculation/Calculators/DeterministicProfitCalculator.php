<?php

namespace App\ProfitCalculation\Calculators;

use App\Enums\Pricing\PriceEstimateStatus;
use App\Enums\Profit\CostCategory;
use App\Enums\Profit\ProfitConfidenceLevel;
use App\Enums\Profit\ProfitEstimateItemKind;
use App\Enums\Profit\ProfitEstimateStatus;
use App\Models\Analysis;
use App\Models\CostInput;
use App\Models\CostInputItem;
use App\Models\PriceEstimate;
use App\Models\RiskAssessment;
use App\ProfitCalculation\Contracts\ProfitCalculator;
use App\ProfitCalculation\Data\ProfitEstimateData;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use LogicException;

class DeterministicProfitCalculator implements ProfitCalculator
{
    public function calculate(
        Analysis $analysis,
        PriceEstimate $priceEstimate,
        RiskAssessment $riskAssessment,
        CostInput $costInput,
    ): ProfitEstimateData {
        $this->guardEvidenceChain(
            $analysis,
            $priceEstimate,
            $riskAssessment,
            $costInput,
        );

        if (
            $priceEstimate->status === PriceEstimateStatus::NeedsInput
            || $priceEstimate->estimate_minor === null
        ) {
            throw new LogicException(
                'Profit calculation requires a completed price estimate.',
            );
        }

        $costInput->loadMissing('items');
        $itemsByCategory = $costInput->items->keyBy(
            static fn (CostInputItem $item): string => $item->category->value,
        );

        if (
            $costInput->items->count() !== count(CostCategory::cases())
            || collect(CostCategory::cases())->contains(
                static fn (CostCategory $category): bool => ! $itemsByCategory->has(
                    $category->value,
                ),
            )
        ) {
            throw new LogicException(
                'Profit calculation requires one immutable item for every cost category.',
            );
        }

        $expectedSalePrice = $priceEstimate->estimate_minor;
        $purchaseItem = $itemsByCategory->get(
            CostCategory::PurchasePrice->value,
        );
        $purchasePrice = $purchaseItem->is_known
            ? $purchaseItem->amount_minor
            : null;
        $knownCosts = $costInput->items
            ->where('is_known', true)
            ->sum('amount_minor');
        $additionalItems = collect(CostCategory::additionalCosts())
            ->map(
                static fn (CostCategory $category): CostInputItem => $itemsByCategory
                    ->get($category->value),
            );
        $additionalCosts = $additionalItems->every(
            static fn (CostInputItem $item): bool => $item->is_known,
        )
            ? (int) $additionalItems->sum('amount_minor')
            : null;
        $allCostsKnown = $costInput->unknown_count === 0;
        $totalCost = $allCostsKnown ? (int) $knownCosts : null;
        $grossMargin = $purchasePrice === null
            ? null
            : $expectedSalePrice - $purchasePrice;
        $netProfit = $totalCost === null
            ? null
            : $expectedSalePrice - $totalCost;
        $profitMargin = $netProfit === null
            ? null
            : $this->boundedRatio($netProfit, $expectedSalePrice);
        $returnOnInvestedCapital = $netProfit === null || $totalCost === null
            ? null
            : $this->boundedRatio($netProfit, $totalCost);
        $ratioOutOfBounds = $netProfit !== null
            && ($profitMargin === null || $returnOnInvestedCapital === null);
        $crossBorder = $costInput->source_country_code
            !== $costInput->target_country_code;
        $compatibilityMissing = $crossBorder
            && $costInput->regional_compatibility_confirmed !== true;
        $unknownCount = $costInput->unknown_count
            + ($compatibilityMissing ? 1 : 0)
            + ($ratioOutOfBounds ? 1 : 0);
        $reasonCodes = [];

        foreach (CostCategory::cases() as $category) {
            $item = $itemsByCategory->get($category->value);

            if (! $item->is_known) {
                $reasonCodes[] = "cost_{$category->value}_unknown";
            }
        }

        if ($crossBorder) {
            foreach ([
                CostCategory::Transport,
                CostCategory::Customs,
                CostCategory::Tax,
            ] as $requiredCategory) {
                if (! $itemsByCategory->get($requiredCategory->value)->is_known) {
                    $reasonCodes[] = "cross_border_{$requiredCategory->value}_unknown";
                }
            }

            if ($compatibilityMissing) {
                $reasonCodes[] = 'regional_compatibility_unconfirmed';
            } else {
                $reasonCodes[] = 'cross_border_costs_and_compatibility_confirmed';
            }
        } else {
            $reasonCodes[] = 'domestic_market_scope';
        }

        if ($costInput->unknown_count > 0) {
            $reasonCodes[] = 'profit_estimate_has_unknown_costs';
        } else {
            $reasonCodes[] = 'all_cost_inputs_confirmed';
        }

        if ($ratioOutOfBounds) {
            $reasonCodes[] = 'profit_ratio_out_of_bounds';
        }

        if ($netProfit !== null && $netProfit < 0) {
            $reasonCodes[] = 'negative_expected_profit';
        } elseif ($netProfit !== null) {
            $reasonCodes[] = 'non_negative_expected_profit';
        }

        $reasonCodes[] = 'expected_sale_price_from_price_estimate';
        $reasonCodes[] = 'identity_cost_currency_boundary';
        $confidenceComponents = [
            'price_evidence' => $this->weightedConfidence(
                $priceEstimate->confidence_basis_points ?? 0,
                5000,
            ),
            'risk_evidence' => $this->weightedConfidence(
                $riskAssessment->confidence_basis_points,
                2500,
            ),
            'cost_completeness' => intdiv(
                ($costInput->known_count * 2000)
                    + intdiv(count(CostCategory::cases()), 2),
                count(CostCategory::cases()),
            ),
            'market_scope_confirmation' => (
                ! $crossBorder
                || $costInput->regional_compatibility_confirmed === true
            ) ? 500 : 0,
        ];
        $confidence = min(10000, array_sum($confidenceComponents));
        $status = match (true) {
            $unknownCount > 0 => ProfitEstimateStatus::NeedsInput,
            $confidence < (int) config(
                'profit_calculation.low_confidence_basis_points',
            ) => ProfitEstimateStatus::LowConfidence,
            default => ProfitEstimateStatus::Estimated,
        };
        $inputSnapshot = [
            'analysis' => [
                'id' => $analysis->getKey(),
                'request_hash' => $analysis->request_hash,
                'source_country_code' => $analysis->source_country_code,
                'target_country_code' => $analysis->target_country_code,
            ],
            'price_estimate' => [
                'id' => $priceEstimate->getKey(),
                'input_hash' => $priceEstimate->input_hash,
                'algorithm_version' => $priceEstimate->algorithm_version,
                'rate_resolver_version' => $priceEstimate->rate_resolver_version,
                'calculation_at' => $priceEstimate->calculation_at?->toIso8601String(),
                'currency_code' => $priceEstimate->target_currency_code,
                'expected_sale_price_minor' => $expectedSalePrice,
                'confidence_basis_points' => $priceEstimate->confidence_basis_points,
            ],
            'risk_assessment' => [
                'id' => $riskAssessment->getKey(),
                'input_hash' => $riskAssessment->input_hash,
                'evaluator_version' => $riskAssessment->evaluator_version,
                'score' => $riskAssessment->score,
                'confidence_basis_points' => $riskAssessment->confidence_basis_points,
            ],
            'cost_input' => [
                'id' => $costInput->getKey(),
                'input_hash' => $costInput->input_hash,
                'input_version' => $costInput->input_version,
                'currency_code' => $costInput->currency_code,
                'source_country_code' => $costInput->source_country_code,
                'target_country_code' => $costInput->target_country_code,
                'regional_compatibility_confirmed' => (
                    $costInput->regional_compatibility_confirmed
                ),
                'items' => $costInput->items->map(
                    static fn (CostInputItem $item): array => [
                        'id' => $item->getKey(),
                        'category' => $item->category->value,
                        'amount_minor' => $item->amount_minor,
                        'is_known' => $item->is_known,
                        'source' => $item->source,
                    ],
                )->values()->all(),
            ],
        ];
        $inputHash = hash(
            'sha256',
            json_encode(
                [
                    ...$inputSnapshot,
                    'calculation_version' => config(
                        'profit_calculation.calculation_version',
                    ),
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
        );
        $profitItems = [[
            'cost_input_item_id' => null,
            'position' => 1,
            'category' => 'expected_sale_price',
            'kind' => ProfitEstimateItemKind::Revenue,
            'amount_minor' => $expectedSalePrice,
            'is_known' => true,
            'source_snapshot' => [
                'source' => 'price_estimate',
                'price_estimate_id' => $priceEstimate->getKey(),
                'price_estimate_input_hash' => $priceEstimate->input_hash,
            ],
        ]];

        foreach ($costInput->items as $index => $item) {
            $profitItems[] = [
                'cost_input_item_id' => $item->getKey(),
                'position' => $index + 2,
                'category' => $item->category->value,
                'kind' => ProfitEstimateItemKind::Cost,
                'amount_minor' => $item->amount_minor,
                'is_known' => $item->is_known,
                'source_snapshot' => [
                    'source' => $item->source,
                    'cost_input_id' => $costInput->getKey(),
                    'cost_input_hash' => $costInput->input_hash,
                    'cost_input_item_id' => $item->getKey(),
                ],
            ];
        }

        return new ProfitEstimateData(
            status: $status,
            calculationVersion: (string) config(
                'profit_calculation.calculation_version',
            ),
            inputHash: $inputHash,
            calculationAt: CarbonImmutable::now()->utc(),
            currencyCode: $costInput->currency_code,
            expectedSalePriceMinor: $expectedSalePrice,
            purchasePriceMinor: $purchasePrice,
            grossMarginMinor: $grossMargin,
            knownCostsMinor: (int) $knownCosts,
            additionalCostsMinor: $additionalCosts,
            totalCostMinor: $totalCost,
            expectedNetProfitMinor: $netProfit,
            profitMarginBasisPoints: $profitMargin,
            returnOnInvestedCapitalBasisPoints: $returnOnInvestedCapital,
            confidenceBasisPoints: $confidence,
            confidenceLevel: ProfitConfidenceLevel::fromBasisPoints($confidence),
            unknownCount: $unknownCount,
            reasonCodes: array_values(array_unique($reasonCodes)),
            confidenceComponents: $confidenceComponents,
            inputSnapshot: $inputSnapshot,
            items: $profitItems,
        );
    }

    private function guardEvidenceChain(
        Analysis $analysis,
        PriceEstimate $priceEstimate,
        RiskAssessment $riskAssessment,
        CostInput $costInput,
    ): void {
        if (
            $priceEstimate->analysis_id !== $analysis->getKey()
            || $riskAssessment->analysis_id !== $analysis->getKey()
            || $riskAssessment->price_estimate_id !== $priceEstimate->getKey()
            || $costInput->analysis_id !== $analysis->getKey()
            || $costInput->price_estimate_id !== $priceEstimate->getKey()
            || $costInput->risk_assessment_id !== $riskAssessment->getKey()
            || $priceEstimate->organization_id !== $analysis->organization_id
            || $riskAssessment->organization_id !== $analysis->organization_id
            || $costInput->organization_id !== $analysis->organization_id
            || $costInput->currency_code !== $priceEstimate->target_currency_code
        ) {
            throw new LogicException(
                'Profit inputs do not belong to one immutable analysis evidence chain.',
            );
        }
    }

    private function weightedConfidence(int $basisPoints, int $maximum): int
    {
        return intdiv(($basisPoints * $maximum) + 5000, 10000);
    }

    private function boundedRatio(int $numerator, int $denominator): ?int
    {
        if ($denominator <= 0) {
            return null;
        }

        $ratio = BigInteger::of($numerator)
            ->multipliedBy(10000)
            ->dividedBy($denominator, RoundingMode::HalfEven)
            ->toInt();

        return abs($ratio) <= (int) config(
            'profit_calculation.maximum_ratio_basis_points',
        )
            ? $ratio
            : null;
    }
}
