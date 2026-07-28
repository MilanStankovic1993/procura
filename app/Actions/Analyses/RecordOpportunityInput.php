<?php

namespace App\Actions\Analyses;

use App\Enums\Comparables\ComparableDecision;
use App\Enums\Opportunity\OpportunityEvidenceCode;
use App\Enums\Opportunity\ShippingMethod;
use App\Enums\Profit\CostCategory;
use App\Models\Analysis;
use App\Models\ComparableSet;
use App\Models\CostInput;
use App\Models\OpportunityInput;
use App\Models\PriceEstimate;
use App\Models\ProfitEstimate;
use App\Models\RiskAssessment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

class RecordOpportunityInput
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array{input: OpportunityInput, created: bool}
     */
    public function record(
        Analysis $analysis,
        ComparableSet $comparableSet,
        PriceEstimate $priceEstimate,
        RiskAssessment $riskAssessment,
        CostInput $costInput,
        ProfitEstimate $profitEstimate,
        User $actor,
        array $attributes,
    ): array {
        return DB::transaction(function () use (
            $analysis,
            $comparableSet,
            $priceEstimate,
            $riskAssessment,
            $costInput,
            $profitEstimate,
            $actor,
            $attributes,
        ): array {
            $lockedAnalysis = Analysis::query()
                ->lockForUpdate()
                ->findOrFail($analysis->getKey());
            $lockedComparableSet = ComparableSet::query()
                ->with(['items.comparableRecord'])
                ->lockForUpdate()
                ->findOrFail($comparableSet->getKey());
            $lockedPriceEstimate = PriceEstimate::query()
                ->lockForUpdate()
                ->findOrFail($priceEstimate->getKey());
            $lockedRiskAssessment = RiskAssessment::query()
                ->lockForUpdate()
                ->findOrFail($riskAssessment->getKey());
            $lockedCostInput = CostInput::query()
                ->with('items')
                ->lockForUpdate()
                ->findOrFail($costInput->getKey());
            $lockedProfitEstimate = ProfitEstimate::query()
                ->lockForUpdate()
                ->findOrFail($profitEstimate->getKey());

            $this->guardEvidenceChain(
                $lockedAnalysis,
                $lockedComparableSet,
                $lockedPriceEstimate,
                $lockedRiskAssessment,
                $lockedCostInput,
                $lockedProfitEstimate,
            );

            $crossBorder = $lockedAnalysis->source_country_code
                !== $lockedAnalysis->target_country_code;
            $shippingMethod = isset($attributes['shipping_method'])
                ? ShippingMethod::from($attributes['shipping_method'])
                : null;
            $soldCount = $attributes['sold_comparables_count'] ?? null;
            $demandObservedAt = isset($attributes['demand_evidence_observed_at'])
                ? CarbonImmutable::parse(
                    $attributes['demand_evidence_observed_at'],
                )->utc()
                : null;
            $demandEvidenceSource = isset($attributes['demand_evidence_source'])
                ? trim((string) $attributes['demand_evidence_source'])
                : null;
            $demandEvidenceSource = $demandEvidenceSource === ''
                ? null
                : $demandEvidenceSource;
            $transportItem = $lockedCostInput->items->first(
                static fn ($item): bool => (
                    $item->category === CostCategory::Transport
                ),
            );

            if ($transportItem === null) {
                throw new LogicException(
                    'Opportunity evidence requires the exact transport-cost item.',
                );
            }

            $includedItems = $lockedComparableSet->items->filter(
                static fn ($item): bool => (
                    $item->decision === ComparableDecision::Included
                ),
            );
            $comparableAges = $includedItems
                ->map(function ($item) use ($lockedPriceEstimate): int {
                    $observedAt = $item->comparableRecord->observed_at;
                    $cutoff = $lockedPriceEstimate->calculation_at;

                    if ($observedAt === null || $cutoff === null) {
                        throw new LogicException(
                            'Demand evidence requires comparable observation and calculation timestamps.',
                        );
                    }

                    return $observedAt->greaterThan($cutoff)
                        ? 0
                        : (int) floor($observedAt->diffInDays($cutoff));
                })
                ->sort()
                ->values()
                ->all();
            $medianComparableAge = $this->median($comparableAges);
            $userSource = static fn (mixed $value): string => $value === null
                ? 'user_unprovided'
                : 'user_confirmed';
            $items = [
                $this->item(
                    OpportunityEvidenceCode::ShippingMethod,
                    'string',
                    $shippingMethod?->value,
                    $shippingMethod !== null,
                    true,
                    $userSource($shippingMethod),
                ),
                $this->item(
                    OpportunityEvidenceCode::ShippingDistanceKm,
                    'integer',
                    $attributes['shipping_distance_km'] ?? null,
                    is_int($attributes['shipping_distance_km'] ?? null),
                    true,
                    $userSource($attributes['shipping_distance_km'] ?? null),
                ),
                $this->booleanItem(
                    OpportunityEvidenceCode::PickupAvailable,
                    $attributes['pickup_available'] ?? null,
                    true,
                ),
                $this->booleanItem(
                    OpportunityEvidenceCode::TrackingAvailable,
                    $attributes['tracking_available'] ?? null,
                    $shippingMethod !== ShippingMethod::LocalPickup,
                ),
                $this->booleanItem(
                    OpportunityEvidenceCode::InsuranceAvailable,
                    $attributes['insurance_available'] ?? null,
                    $shippingMethod !== ShippingMethod::LocalPickup,
                ),
                $this->booleanItem(
                    OpportunityEvidenceCode::PackagingConfirmed,
                    $attributes['packaging_confirmed'] ?? null,
                    true,
                ),
                $crossBorder
                    ? $this->booleanItem(
                        OpportunityEvidenceCode::CrossBorderHandlingConfirmed,
                        $attributes['cross_border_handling_confirmed'] ?? null,
                        true,
                    )
                    : $this->item(
                        OpportunityEvidenceCode::CrossBorderHandlingConfirmed,
                        'boolean',
                        null,
                        true,
                        false,
                        'not_applicable_domestic',
                    ),
                $this->item(
                    OpportunityEvidenceCode::TransportCostKnown,
                    'boolean',
                    $transportItem->is_known,
                    true,
                    true,
                    'cost_input',
                    [
                        'cost_input_id' => $lockedCostInput->getKey(),
                        'cost_input_item_id' => $transportItem->getKey(),
                        'amount_minor' => $transportItem->amount_minor,
                    ],
                ),
                $crossBorder
                    ? $this->item(
                        OpportunityEvidenceCode::RegionalCompatibilityConfirmed,
                        'boolean',
                        $lockedCostInput->regional_compatibility_confirmed,
                        is_bool(
                            $lockedCostInput->regional_compatibility_confirmed,
                        ),
                        true,
                        'cost_input',
                        [
                            'cost_input_id' => $lockedCostInput->getKey(),
                        ],
                    )
                    : $this->item(
                        OpportunityEvidenceCode::RegionalCompatibilityConfirmed,
                        'boolean',
                        null,
                        true,
                        false,
                        'not_applicable_domestic',
                    ),
                $this->item(
                    OpportunityEvidenceCode::SoldComparablesCount,
                    'integer',
                    $soldCount,
                    is_int($soldCount),
                    true,
                    $userSource($soldCount),
                ),
                $this->item(
                    OpportunityEvidenceCode::MedianDaysToSale,
                    'integer',
                    $attributes['median_days_to_sale'] ?? null,
                    $soldCount === 0
                        || is_int($attributes['median_days_to_sale'] ?? null),
                    is_int($soldCount) && $soldCount > 0,
                    $soldCount === 0
                        ? 'not_applicable_no_sales'
                        : $userSource($attributes['median_days_to_sale'] ?? null),
                ),
                $this->item(
                    OpportunityEvidenceCode::ObservationWindowDays,
                    'integer',
                    $attributes['observation_window_days'] ?? null,
                    is_int($attributes['observation_window_days'] ?? null),
                    true,
                    $userSource($attributes['observation_window_days'] ?? null),
                ),
                $this->item(
                    OpportunityEvidenceCode::DemandEvidenceObservedAt,
                    'datetime',
                    $demandObservedAt?->toIso8601String(),
                    $demandObservedAt !== null,
                    true,
                    $userSource($demandObservedAt),
                ),
                $this->item(
                    OpportunityEvidenceCode::DemandEvidenceSource,
                    'string',
                    $demandEvidenceSource,
                    $demandEvidenceSource !== null,
                    true,
                    $userSource($demandEvidenceSource),
                ),
                $this->item(
                    OpportunityEvidenceCode::ComparableCount,
                    'integer',
                    count($comparableAges),
                    true,
                    true,
                    'comparable_set',
                    [
                        'comparable_set_id' => $lockedComparableSet->getKey(),
                        'included_item_ids' => $includedItems
                            ->pluck('id')
                            ->values()
                            ->all(),
                    ],
                ),
                $this->item(
                    OpportunityEvidenceCode::MedianComparableAgeDays,
                    'integer',
                    $medianComparableAge,
                    $medianComparableAge !== null,
                    true,
                    'comparable_set',
                    [
                        'price_estimate_calculation_at' => (
                            $lockedPriceEstimate->calculation_at?->toIso8601String()
                        ),
                        'comparable_age_days' => $comparableAges,
                    ],
                ),
            ];
            $snapshot = [
                'analysis' => [
                    'id' => $lockedAnalysis->getKey(),
                    'request_hash' => $lockedAnalysis->request_hash,
                    'source_country_code' => $lockedAnalysis->source_country_code,
                    'target_country_code' => $lockedAnalysis->target_country_code,
                ],
                'comparable_set' => [
                    'id' => $lockedComparableSet->getKey(),
                    'input_hash' => $lockedComparableSet->input_hash,
                ],
                'price_estimate' => [
                    'id' => $lockedPriceEstimate->getKey(),
                    'input_hash' => $lockedPriceEstimate->input_hash,
                ],
                'risk_assessment' => [
                    'id' => $lockedRiskAssessment->getKey(),
                    'input_hash' => $lockedRiskAssessment->input_hash,
                ],
                'cost_input' => [
                    'id' => $lockedCostInput->getKey(),
                    'input_hash' => $lockedCostInput->input_hash,
                ],
                'profit_estimate' => [
                    'id' => $lockedProfitEstimate->getKey(),
                    'input_hash' => $lockedProfitEstimate->input_hash,
                ],
                'input_version' => config(
                    'opportunity_assessment.input_version',
                ),
                'items' => array_map(
                    static fn (array $item): array => [
                        'component' => $item['component']->value,
                        'position' => $item['position'],
                        'code' => $item['code']->value,
                        'value_type' => $item['value_type'],
                        'value_payload' => $item['value_payload'],
                        'is_known' => $item['is_known'],
                        'is_required' => $item['is_required'],
                        'source' => $item['source'],
                        'evidence_snapshot' => $item['evidence_snapshot'],
                    ],
                    $items,
                ),
            ];
            $inputHash = hash(
                'sha256',
                json_encode(
                    $snapshot,
                    JSON_THROW_ON_ERROR
                        | JSON_UNESCAPED_SLASHES
                        | JSON_UNESCAPED_UNICODE,
                ),
            );
            $latest = OpportunityInput::query()
                ->where('analysis_id', $lockedAnalysis->getKey())
                ->where('profit_estimate_id', $lockedProfitEstimate->getKey())
                ->orderByDesc('run_number')
                ->lockForUpdate()
                ->first();

            if ($latest?->input_hash === $inputHash) {
                return [
                    'input' => $latest->load('items'),
                    'created' => false,
                ];
            }

            $runNumber = ((int) OpportunityInput::query()
                ->where('analysis_id', $lockedAnalysis->getKey())
                ->max('run_number')) + 1;
            $knownCount = count(array_filter(
                $items,
                static fn (array $item): bool => (
                    $item['is_required'] && $item['is_known']
                ),
            ));
            $unknownCount = count(array_filter(
                $items,
                static fn (array $item): bool => (
                    $item['is_required'] && ! $item['is_known']
                ),
            ));
            $input = OpportunityInput::query()->create([
                'organization_id' => $lockedAnalysis->organization_id,
                'analysis_id' => $lockedAnalysis->getKey(),
                'comparable_set_id' => $lockedComparableSet->getKey(),
                'price_estimate_id' => $lockedPriceEstimate->getKey(),
                'risk_assessment_id' => $lockedRiskAssessment->getKey(),
                'cost_input_id' => $lockedCostInput->getKey(),
                'profit_estimate_id' => $lockedProfitEstimate->getKey(),
                'submitted_by_user_id' => $actor->getKey(),
                'run_number' => $runNumber,
                'input_version' => config(
                    'opportunity_assessment.input_version',
                ),
                'input_hash' => $inputHash,
                'input_key' => hash('sha256', implode('|', [
                    $lockedAnalysis->getKey(),
                    $lockedProfitEstimate->getKey(),
                    (string) config('opportunity_assessment.input_version'),
                    $inputHash,
                    (string) $runNumber,
                ])),
                'source_country_code' => $lockedAnalysis->source_country_code,
                'target_country_code' => $lockedAnalysis->target_country_code,
                'known_count' => $knownCount,
                'unknown_count' => $unknownCount,
                'input_snapshot' => $snapshot,
                'submitted_at' => now(),
            ]);
            $input->items()->createMany($items);

            return [
                'input' => $input->load('items'),
                'created' => true,
            ];
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function item(
        OpportunityEvidenceCode $code,
        string $valueType,
        mixed $value,
        bool $isKnown,
        bool $isRequired,
        string $source,
        array $evidence = [],
    ): array {
        return [
            'component' => $code->component(),
            'position' => $code->position(),
            'code' => $code,
            'value_type' => $valueType,
            'value_payload' => ['value' => $value],
            'is_known' => $isKnown,
            'is_required' => $isRequired,
            'source' => $source,
            'evidence_snapshot' => $evidence,
        ];
    }

    /** @return array<string, mixed> */
    private function booleanItem(
        OpportunityEvidenceCode $code,
        mixed $value,
        bool $isRequired,
    ): array {
        return $this->item(
            $code,
            'boolean',
            is_bool($value) ? $value : null,
            is_bool($value),
            $isRequired,
            is_bool($value) ? 'user_confirmed' : 'user_unprovided',
        );
    }

    /** @param list<int> $values */
    private function median(array $values): ?int
    {
        $count = count($values);

        if ($count === 0) {
            return null;
        }

        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? $values[$middle]
            : intdiv($values[$middle - 1] + $values[$middle] + 1, 2);
    }

    private function guardEvidenceChain(
        Analysis $analysis,
        ComparableSet $comparableSet,
        PriceEstimate $priceEstimate,
        RiskAssessment $riskAssessment,
        CostInput $costInput,
        ProfitEstimate $profitEstimate,
    ): void {
        if (
            $comparableSet->analysis_id !== $analysis->getKey()
            || $priceEstimate->analysis_id !== $analysis->getKey()
            || $priceEstimate->comparable_set_id !== $comparableSet->getKey()
            || $riskAssessment->analysis_id !== $analysis->getKey()
            || $riskAssessment->comparable_set_id !== $comparableSet->getKey()
            || $riskAssessment->price_estimate_id !== $priceEstimate->getKey()
            || $costInput->analysis_id !== $analysis->getKey()
            || $costInput->price_estimate_id !== $priceEstimate->getKey()
            || $costInput->risk_assessment_id !== $riskAssessment->getKey()
            || $profitEstimate->analysis_id !== $analysis->getKey()
            || $profitEstimate->price_estimate_id !== $priceEstimate->getKey()
            || $profitEstimate->risk_assessment_id !== $riskAssessment->getKey()
            || $profitEstimate->cost_input_id !== $costInput->getKey()
            || collect([
                $comparableSet,
                $priceEstimate,
                $riskAssessment,
                $costInput,
                $profitEstimate,
            ])->contains(
                static fn ($record): bool => (
                    $record->organization_id !== $analysis->organization_id
                ),
            )
        ) {
            throw new LogicException(
                'Opportunity inputs require one immutable analysis evidence chain.',
            );
        }
    }
}
