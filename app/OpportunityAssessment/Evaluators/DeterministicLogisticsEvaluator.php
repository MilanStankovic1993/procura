<?php

namespace App\OpportunityAssessment\Evaluators;

use App\Enums\Opportunity\OpportunityAssessmentStatus;
use App\Enums\Opportunity\OpportunityComponent;
use App\Enums\Opportunity\OpportunityConfidenceLevel;
use App\Enums\Opportunity\OpportunityEvidenceCode;
use App\Enums\Opportunity\ShippingMethod;
use App\Models\Analysis;
use App\Models\OpportunityInput;
use App\Models\OpportunityInputItem;
use App\Models\ProfitEstimate;
use App\OpportunityAssessment\Contracts\LogisticsEvaluator;
use App\OpportunityAssessment\Data\OpportunityAssessmentData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use LogicException;

class DeterministicLogisticsEvaluator implements LogisticsEvaluator
{
    public function evaluate(
        Analysis $analysis,
        ProfitEstimate $profitEstimate,
        OpportunityInput $input,
    ): OpportunityAssessmentData {
        $this->guardEvidenceChain($analysis, $profitEstimate, $input);
        $profitEstimate->loadMissing(['priceEstimate', 'riskAssessment']);
        $input->loadMissing('items');
        $componentItems = $input->items
            ->filter(
                static fn (OpportunityInputItem $item): bool => (
                    $item->component === OpportunityComponent::Logistics
                ),
            )
            ->keyBy(
                static fn (OpportunityInputItem $item): string => (
                    $item->code->value
                ),
            );
        $requiredItems = $componentItems->where('is_required', true);
        $unknownItems = $requiredItems->where('is_known', false);
        $shippingMethodValue = $this->value(
            $componentItems,
            OpportunityEvidenceCode::ShippingMethod,
        );
        $shippingMethod = is_string($shippingMethodValue)
            ? ShippingMethod::from($shippingMethodValue)
            : null;
        $distance = $this->integerValue(
            $componentItems,
            OpportunityEvidenceCode::ShippingDistanceKm,
        );
        $pickup = $this->booleanValue(
            $componentItems,
            OpportunityEvidenceCode::PickupAvailable,
        );
        $tracking = $this->booleanValue(
            $componentItems,
            OpportunityEvidenceCode::TrackingAvailable,
        );
        $insurance = $this->booleanValue(
            $componentItems,
            OpportunityEvidenceCode::InsuranceAvailable,
        );
        $packaging = $this->booleanValue(
            $componentItems,
            OpportunityEvidenceCode::PackagingConfirmed,
        );
        $crossBorderHandling = $this->booleanValue(
            $componentItems,
            OpportunityEvidenceCode::CrossBorderHandlingConfirmed,
        );
        $transportCostKnown = $this->booleanValue(
            $componentItems,
            OpportunityEvidenceCode::TransportCostKnown,
        );
        $compatibility = $this->booleanValue(
            $componentItems,
            OpportunityEvidenceCode::RegionalCompatibilityConfirmed,
        );
        $crossBorder = $analysis->source_country_code
            !== $analysis->target_country_code;
        $methodPoints = match ($shippingMethod) {
            ShippingMethod::LocalPickup => 20,
            ShippingMethod::Parcel => 16,
            ShippingMethod::SellerArranged => 10,
            ShippingMethod::Freight => 5,
            null => null,
        };
        $distancePoints = match (true) {
            $distance === null => null,
            $distance <= 25 => 15,
            $distance <= 100 => 12,
            $distance <= 500 => 8,
            $distance <= 1500 => 4,
            default => 0,
        };
        $pickupPoints = $pickup === null ? null : ($pickup ? 10 : 4);
        $transportPoints = $transportCostKnown === null
            ? null
            : ($transportCostKnown ? 15 : 0);
        $trackingPoints = $shippingMethod === ShippingMethod::LocalPickup
            ? 10
            : ($tracking === null ? null : ($tracking ? 10 : 2));
        $insurancePoints = $shippingMethod === ShippingMethod::LocalPickup
            ? 10
            : ($insurance === null ? null : ($insurance ? 10 : 2));
        $packagingPoints = $packaging === null ? null : ($packaging ? 10 : 2);
        $routePoints = ! $crossBorder
            ? 10
            : (
                $crossBorderHandling === null || $compatibility === null
                    ? null
                    : ($crossBorderHandling && $compatibility ? 10 : 0)
            );
        $criterionValues = [
            [
                OpportunityEvidenceCode::ShippingMethod,
                'shipping_method_simplicity',
                20,
                $methodPoints,
            ],
            [
                OpportunityEvidenceCode::ShippingDistanceKm,
                'shipping_distance',
                15,
                $distancePoints,
            ],
            [
                OpportunityEvidenceCode::PickupAvailable,
                'pickup_availability',
                10,
                $pickupPoints,
            ],
            [
                OpportunityEvidenceCode::TransportCostKnown,
                'transport_cost_certainty',
                15,
                $transportPoints,
            ],
            [
                OpportunityEvidenceCode::TrackingAvailable,
                'tracking',
                10,
                $trackingPoints,
            ],
            [
                OpportunityEvidenceCode::InsuranceAvailable,
                'insurance',
                10,
                $insurancePoints,
            ],
            [
                OpportunityEvidenceCode::PackagingConfirmed,
                'packaging',
                10,
                $packagingPoints,
            ],
            [
                OpportunityEvidenceCode::CrossBorderHandlingConfirmed,
                'route_readiness',
                10,
                $routePoints,
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
                    'cross_border' => $crossBorder,
                    'regional_compatibility_confirmed' => $compatibility,
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
        $reasonCodes[] = $crossBorder
            ? 'cross_border_logistics_scope'
            : 'domestic_logistics_scope';

        if ($shippingMethod === ShippingMethod::LocalPickup) {
            $reasonCodes[] = 'tracking_and_insurance_not_applicable_to_pickup';
        }

        if ($crossBorder && $crossBorderHandling === false) {
            $reasonCodes[] = 'cross_border_handling_not_confirmed';
        }

        if ($crossBorder && $compatibility === false) {
            $reasonCodes[] = 'regional_compatibility_not_confirmed';
        }

        $requiredCount = $requiredItems->count();
        $confidenceComponents = [
            'explicit_evidence' => $requiredCount === 0
                ? 0
                : intdiv(
                    (($requiredCount - $unknownCount) * 6000)
                        + intdiv($requiredCount, 2),
                    $requiredCount,
                ),
            'profit_evidence' => $this->weightedConfidence(
                $profitEstimate->confidence_basis_points,
                2000,
            ),
            'risk_evidence' => $this->weightedConfidence(
                $profitEstimate->riskAssessment->confidence_basis_points,
                1000,
            ),
            'price_evidence' => $this->weightedConfidence(
                $profitEstimate->priceEstimate->confidence_basis_points ?? 0,
                1000,
            ),
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
                'confidence_basis_points' => (
                    $profitEstimate->confidence_basis_points
                ),
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
            'opportunity_assessment.logistics_evaluator_version',
        );

        return new OpportunityAssessmentData(
            component: OpportunityComponent::Logistics,
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

    /**
     * @param  Collection<string, OpportunityInputItem>  $items
     */
    private function booleanValue(
        Collection $items,
        OpportunityEvidenceCode $code,
    ): ?bool {
        $value = $this->value($items, $code);

        return is_bool($value) ? $value : null;
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
                'Logistics assessment inputs do not share one evidence chain.',
            );
        }
    }
}
