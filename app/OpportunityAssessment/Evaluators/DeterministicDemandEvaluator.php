<?php

namespace App\OpportunityAssessment\Evaluators;

use App\Enums\Opportunity\OpportunityAssessmentStatus;
use App\Enums\Opportunity\OpportunityComponent;
use App\Enums\Opportunity\OpportunityConfidenceLevel;
use App\Enums\Opportunity\OpportunityEvidenceCode;
use App\Models\Analysis;
use App\Models\OpportunityInput;
use App\Models\OpportunityInputItem;
use App\Models\ProfitEstimate;
use App\OpportunityAssessment\Contracts\DemandEvaluator;
use App\OpportunityAssessment\Data\OpportunityAssessmentData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use LogicException;

class DeterministicDemandEvaluator implements DemandEvaluator
{
    public function evaluate(
        Analysis $analysis,
        ProfitEstimate $profitEstimate,
        OpportunityInput $input,
    ): OpportunityAssessmentData {
        $this->guardEvidenceChain($analysis, $profitEstimate, $input);
        $profitEstimate->loadMissing('priceEstimate');
        $input->loadMissing('items');
        $componentItems = $input->items
            ->filter(
                static fn (OpportunityInputItem $item): bool => (
                    $item->component === OpportunityComponent::Demand
                ),
            )
            ->keyBy(
                static fn (OpportunityInputItem $item): string => (
                    $item->code->value
                ),
            );
        $requiredItems = $componentItems->where('is_required', true);
        $unknownItems = $requiredItems->where('is_known', false);
        $soldCount = $this->integerValue(
            $componentItems,
            OpportunityEvidenceCode::SoldComparablesCount,
        );
        $medianDaysToSale = $this->integerValue(
            $componentItems,
            OpportunityEvidenceCode::MedianDaysToSale,
        );
        $observationWindow = $this->integerValue(
            $componentItems,
            OpportunityEvidenceCode::ObservationWindowDays,
        );
        $comparableCount = $this->integerValue(
            $componentItems,
            OpportunityEvidenceCode::ComparableCount,
        );
        $medianComparableAge = $this->integerValue(
            $componentItems,
            OpportunityEvidenceCode::MedianComparableAgeDays,
        );
        $observedAtValue = $this->value(
            $componentItems,
            OpportunityEvidenceCode::DemandEvidenceObservedAt,
        );
        $observedAt = is_string($observedAtValue)
            ? CarbonImmutable::parse($observedAtValue)->utc()
            : null;
        $marketBreadthPoints = match (true) {
            $comparableCount === null => null,
            $comparableCount >= 10 => 25,
            $comparableCount >= 7 => 20,
            $comparableCount >= 5 => 15,
            $comparableCount >= 3 => 10,
            $comparableCount >= 1 => 4,
            default => 0,
        };
        $marketRecencyPoints = match (true) {
            $medianComparableAge === null => null,
            $medianComparableAge <= 14 => 20,
            $medianComparableAge <= 30 => 16,
            $medianComparableAge <= 90 => 10,
            $medianComparableAge <= 180 => 4,
            default => 0,
        };
        $saleObservationPoints = $soldCount === null
            || $observationWindow === null
            ? null
            : $this->saleRatePoints($soldCount, $observationWindow);
        $velocityPoints = match (true) {
            $soldCount === null => null,
            $soldCount === 0 => 0,
            $medianDaysToSale === null => null,
            $medianDaysToSale <= 7 => 30,
            $medianDaysToSale <= 30 => 24,
            $medianDaysToSale <= 60 => 16,
            $medianDaysToSale <= 120 => 8,
            default => 2,
        };
        $criterionValues = [
            [
                OpportunityEvidenceCode::ComparableCount,
                'market_evidence_breadth',
                25,
                $marketBreadthPoints,
            ],
            [
                OpportunityEvidenceCode::MedianComparableAgeDays,
                'market_evidence_recency',
                20,
                $marketRecencyPoints,
            ],
            [
                OpportunityEvidenceCode::SoldComparablesCount,
                'observed_sales_rate',
                25,
                $saleObservationPoints,
            ],
            [
                OpportunityEvidenceCode::MedianDaysToSale,
                'observed_sale_velocity',
                30,
                $velocityPoints,
            ],
        ];
        $assessmentItems = [];

        foreach ($criterionValues as $index => [
            $evidenceCode,
            $criterionCode,
            $maximum,
            $contribution,
        ]) {
            $inputItem = $componentItems->get($evidenceCode->value);
            $assessmentItems[] = [
                'opportunity_input_item_id' => $inputItem?->getKey(),
                'position' => $index + 1,
                'code' => $criterionCode,
                'maximum_points' => $maximum,
                'score_contribution' => $contribution,
                'is_known' => $contribution !== null,
                'source_snapshot' => [
                    'evidence_code' => $evidenceCode->value,
                    'value' => $inputItem?->value(),
                    'source' => $inputItem?->source,
                    'evidence' => $inputItem?->evidence_snapshot,
                    'observation_window_days' => $observationWindow,
                    'sold_comparables_count' => $soldCount,
                ],
            ];
        }

        $unknownCount = $unknownItems->count();
        $score = $unknownCount === 0
            ? (int) collect($assessmentItems)->sum('score_contribution')
            : null;
        $reasonCodes = $unknownItems
            ->map(
                static fn (OpportunityInputItem $item): string => (
                    "opportunity_{$item->code->value}_unknown"
                ),
            )
            ->values()
            ->all();
        $reasonCodes[] = 'asking_comparables_do_not_prove_sales';

        if ($soldCount === 0) {
            $reasonCodes[] = 'no_sold_comparables_observed';
        } elseif ($soldCount !== null) {
            $reasonCodes[] = 'explicit_sold_comparable_evidence_recorded';
        }

        $requiredCount = $requiredItems->count();
        $observationAge = $observedAt === null || $input->submitted_at === null
            ? null
            : (
                $observedAt->greaterThan($input->submitted_at)
                    ? 0
                    : (int) floor(
                        $observedAt->diffInDays($input->submitted_at),
                    )
            );
        $confidenceComponents = [
            'price_and_comparable_evidence' => $this->weightedConfidence(
                $profitEstimate->priceEstimate->confidence_basis_points ?? 0,
                3000,
            ),
            'comparable_depth' => min(
                2000,
                (int) (($comparableCount ?? 0) * 200),
            ),
            'sale_evidence_completeness' => $requiredCount === 0
                ? 0
                : intdiv(
                    (($requiredCount - $unknownCount) * 4000)
                        + intdiv($requiredCount, 2),
                    $requiredCount,
                ),
            'sale_evidence_recency' => match (true) {
                $observationAge === null => 0,
                $observationAge <= 30 => 1000,
                $observationAge <= 90 => 700,
                $observationAge <= 180 => 400,
                default => 100,
            },
        ];
        $confidence = min(10000, array_sum($confidenceComponents));
        $status = match (true) {
            $unknownCount > 0 => OpportunityAssessmentStatus::NeedsInput,
            $confidence < (int) config(
                'opportunity_assessment.low_confidence_basis_points',
            ) => OpportunityAssessmentStatus::LowConfidence,
            default => OpportunityAssessmentStatus::Assessed,
        };
        $snapshot = [
            'analysis' => [
                'id' => $analysis->getKey(),
                'request_hash' => $analysis->request_hash,
            ],
            'profit_estimate' => [
                'id' => $profitEstimate->getKey(),
                'input_hash' => $profitEstimate->input_hash,
            ],
            'opportunity_input' => [
                'id' => $input->getKey(),
                'input_hash' => $input->input_hash,
                'items' => $componentItems->values()->map(
                    static fn (OpportunityInputItem $item): array => [
                        'id' => $item->getKey(),
                        'code' => $item->code->value,
                        'value' => $item->value(),
                        'is_known' => $item->is_known,
                        'is_required' => $item->is_required,
                        'source' => $item->source,
                    ],
                )->all(),
            ],
        ];
        $evaluatorVersion = (string) config(
            'opportunity_assessment.demand_evaluator_version',
        );

        return new OpportunityAssessmentData(
            component: OpportunityComponent::Demand,
            status: $status,
            evaluatorVersion: $evaluatorVersion,
            inputHash: hash(
                'sha256',
                json_encode(
                    [
                        ...$snapshot,
                        'evaluator_version' => $evaluatorVersion,
                    ],
                    JSON_THROW_ON_ERROR
                        | JSON_UNESCAPED_SLASHES
                        | JSON_UNESCAPED_UNICODE,
                ),
            ),
            calculatedAt: CarbonImmutable::now()->utc(),
            score: $score,
            confidenceBasisPoints: $confidence,
            confidenceLevel: OpportunityConfidenceLevel::fromBasisPoints(
                $confidence,
            ),
            unknownCount: $unknownCount,
            reasonCodes: array_values(array_unique($reasonCodes)),
            confidenceComponents: $confidenceComponents,
            inputSnapshot: $snapshot,
            items: $assessmentItems,
        );
    }

    private function saleRatePoints(int $soldCount, int $windowDays): int
    {
        $normalizedNumerator = $soldCount * 30;

        return match (true) {
            $normalizedNumerator >= 10 * $windowDays => 25,
            $normalizedNumerator >= 5 * $windowDays => 20,
            $normalizedNumerator >= 2 * $windowDays => 15,
            $normalizedNumerator >= $windowDays => 10,
            $soldCount > 0 => 5,
            default => 0,
        };
    }

    /**
     * @param  Collection<string, OpportunityInputItem>  $items
     */
    private function value(
        Collection $items,
        OpportunityEvidenceCode $code,
    ): mixed {
        return $items->get($code->value)?->value();
    }

    /**
     * @param  Collection<string, OpportunityInputItem>  $items
     */
    private function integerValue(
        Collection $items,
        OpportunityEvidenceCode $code,
    ): ?int {
        $value = $this->value($items, $code);

        return is_int($value) ? $value : null;
    }

    private function weightedConfidence(int $basisPoints, int $maximum): int
    {
        return intdiv(($basisPoints * $maximum) + 5000, 10000);
    }

    private function guardEvidenceChain(
        Analysis $analysis,
        ProfitEstimate $profitEstimate,
        OpportunityInput $input,
    ): void {
        if (
            $profitEstimate->analysis_id !== $analysis->getKey()
            || $input->analysis_id !== $analysis->getKey()
            || $input->profit_estimate_id !== $profitEstimate->getKey()
            || $input->price_estimate_id !== $profitEstimate->price_estimate_id
            || $input->risk_assessment_id
                !== $profitEstimate->risk_assessment_id
            || $input->cost_input_id !== $profitEstimate->cost_input_id
            || $profitEstimate->organization_id !== $analysis->organization_id
            || $input->organization_id !== $analysis->organization_id
        ) {
            throw new LogicException(
                'Demand assessment inputs do not share one evidence chain.',
            );
        }
    }
}
