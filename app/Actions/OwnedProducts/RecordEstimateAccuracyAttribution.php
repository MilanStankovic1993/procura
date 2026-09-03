<?php

namespace App\Actions\OwnedProducts;

use App\Enums\Analyses\AnalysisType;
use App\Enums\Api\ApiErrorCode;
use App\Enums\Organizations\OrganizationPermission;
use App\Enums\Outcomes\ActualSaleOutcomeType;
use App\Enums\Profit\ProfitEstimateStatus;
use App\Enums\Validation\ApplicationValidationCode;
use App\EstimateAccuracy\Contracts\EstimateAccuracyCalculator;
use App\EstimateAccuracy\Data\EstimateAccuracyReportData;
use App\Exceptions\OutcomeTrackingConflictException;
use App\Models\ActualCostSnapshot;
use App\Models\ActualPurchase;
use App\Models\ActualSale;
use App\Models\Analysis;
use App\Models\EstimateAccuracyReport;
use App\Models\Organization;
use App\Models\OutcomeEstimateAttribution;
use App\Models\OwnedProduct;
use App\Models\ProfitEstimate;
use App\Models\RealizedProfit;
use App\Models\User;
use App\Support\Validation\ApplicationValidation;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use JsonException;

final class RecordEstimateAccuracyAttribution
{
    public function __construct(
        private readonly OwnedProductAuthorizer $authorizer,
        private readonly EstimateAccuracyCalculator $calculator,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{
     *   attribution: OutcomeEstimateAttribution,
     *   report: EstimateAccuracyReport,
     *   created: bool
     * }
     *
     * @throws JsonException
     */
    public function record(
        Organization $organization,
        User $actor,
        string $ownedProductId,
        array $attributes,
    ): array {
        return DB::transaction(function () use (
            $organization,
            $actor,
            $ownedProductId,
            $attributes,
        ): array {
            $this->authorizer->authorize(
                $organization,
                $actor,
                OrganizationPermission::ManageOwnedProducts,
                lockForUpdate: true,
            );
            $product = OwnedProduct::query()
                ->forOrganization($organization)
                ->lockForUpdate()
                ->findOrFail($ownedProductId);
            $payloadHash = $this->payloadHash(
                $organization,
                $actor,
                $product,
                $attributes,
            );
            $idempotent = OutcomeEstimateAttribution::query()
                ->forOrganization($organization)
                ->where('idempotency_key', $attributes['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($idempotent !== null) {
                return $this->idempotent($idempotent, $payloadHash);
            }

            $currentAttribution = OutcomeEstimateAttribution::query()
                ->where('owned_product_id', $product->getKey())
                ->orderByDesc('sequence')
                ->lockForUpdate()
                ->first();
            $currentReport = EstimateAccuracyReport::query()
                ->where('owned_product_id', $product->getKey())
                ->orderByDesc('sequence')
                ->lockForUpdate()
                ->first();
            $this->guardHeads(
                $attributes['expected_current_attribution_id'],
                $attributes['expected_current_accuracy_report_id'],
                $currentAttribution,
                $currentReport,
                $attributes['correction_reason'] ?? null,
            );
            $realizedProfit = $this->currentRealizedProfit(
                $organization,
                $product,
                $attributes['realized_profit_id'],
            );
            [$analysis, $estimate] = $this->currentProfitEstimate(
                $organization,
                $attributes['analysis_id'],
                $attributes['profit_estimate_id'],
            );
            $sequence = ($currentAttribution?->sequence ?? 0) + 1;

            if ($sequence > (int) config(
                'estimate_accuracy.maximum_records_per_product',
            )) {
                ApplicationValidation::fail(
                    'owned_product',
                    ApplicationValidationCode::EstimateAttributionHistoryLimit,
                );
            }

            $snapshot = [
                'organization_id' => $organization->getKey(),
                'owned_product_id' => $product->getKey(),
                'previous_attribution_id' => (
                    $currentAttribution?->getKey()
                ),
                'realized_profit' => [
                    'id' => $realizedProfit->getKey(),
                    'input_hash' => $realizedProfit->input_hash,
                    'calculation_key' => $realizedProfit->calculation_key,
                ],
                'analysis' => [
                    'id' => $analysis->getKey(),
                    'request_hash' => $analysis->request_hash,
                    'listing_snapshot_id' => $analysis->listing_snapshot_id,
                ],
                'profit_estimate' => [
                    'id' => $estimate->getKey(),
                    'input_hash' => $estimate->input_hash,
                    'estimate_key' => $estimate->estimate_key,
                ],
                'reason_code' => $attributes['reason_code'],
                'evidence_kind' => $attributes['evidence_kind'],
                'evidence_reference' => (
                    $attributes['evidence_reference'] ?? null
                ),
                'correction_reason' => (
                    $attributes['correction_reason'] ?? null
                ),
                'note' => $attributes['note'] ?? null,
                'attribution_method' => 'explicit_manual_confirmation',
            ];
            $inputHash = $this->hash($snapshot);

            try {
                $attribution = OutcomeEstimateAttribution::query()->create([
                    'organization_id' => $organization->getKey(),
                    'owned_product_id' => $product->getKey(),
                    'realized_profit_id' => $realizedProfit->getKey(),
                    'analysis_id' => $analysis->getKey(),
                    'profit_estimate_id' => $estimate->getKey(),
                    'previous_attribution_id' => (
                        $currentAttribution?->getKey()
                    ),
                    'actor_user_id' => $actor->getKey(),
                    'sequence' => $sequence,
                    'reason_code' => $attributes['reason_code'],
                    'evidence_kind' => $attributes['evidence_kind'],
                    'evidence_reference' => (
                        $attributes['evidence_reference'] ?? null
                    ),
                    'correction_reason' => (
                        $attributes['correction_reason'] ?? null
                    ),
                    'note' => $attributes['note'] ?? null,
                    'idempotency_key' => $attributes['idempotency_key'],
                    'payload_hash' => $payloadHash,
                    'input_hash' => $inputHash,
                    'attribution_snapshot' => $snapshot,
                    'attributed_at' => CarbonImmutable::now()->utc(),
                ]);
            } catch (QueryException $exception) {
                $collision = OutcomeEstimateAttribution::query()
                    ->forOrganization($organization)
                    ->where(
                        'idempotency_key',
                        $attributes['idempotency_key'],
                    )
                    ->first();

                if ($collision === null) {
                    throw $exception;
                }

                return $this->idempotent($collision, $payloadHash);
            }

            $calculation = $this->calculator->calculate(
                $estimate,
                $realizedProfit,
            );
            $report = EstimateAccuracyReport::query()->create([
                'organization_id' => $organization->getKey(),
                'owned_product_id' => $product->getKey(),
                'outcome_estimate_attribution_id' => $attribution->getKey(),
                'realized_profit_id' => $realizedProfit->getKey(),
                'analysis_id' => $analysis->getKey(),
                'profit_estimate_id' => $estimate->getKey(),
                'previous_report_id' => $currentReport?->getKey(),
                'sequence' => $sequence,
                'status' => $calculation->status->value,
                'calculation_version' => $calculation->calculationVersion,
                'calculation_key' => hash('sha256', implode('|', [
                    $product->getKey(),
                    $attribution->getKey(),
                    $realizedProfit->input_hash,
                    $estimate->input_hash,
                    $calculation->calculationVersion,
                    $calculation->inputHash,
                ])),
                'input_hash' => $calculation->inputHash,
                'source_currency_code' => $estimate->currency_code,
                'reporting_currency_code' => $realizedProfit->currency_code,
                'exchange_rate_id' => (
                    $calculation->conversion->exchangeRateId
                ),
                'rate_direction' => (
                    $calculation->conversion->direction->value
                ),
                'rate_value' => $calculation->conversion->rateValue,
                'rate_effective_at' => (
                    $calculation->conversion->effectiveAt
                ),
                'rate_provider' => $calculation->conversion->provider,
                'rate_provider_reference' => (
                    $calculation->conversion->providerReference
                ),
                'conversion_calculated_at' => $calculation->calculatedAt,
                ...$this->metricColumns($calculation),
                'expected_sale_duration_seconds' => null,
                'actual_sale_duration_seconds' => (
                    $realizedProfit->sale_duration_seconds
                ),
                'sale_duration_signed_error_seconds' => null,
                'sale_duration_absolute_error_seconds' => null,
                'reason_codes' => $calculation->reasonCodes,
                'unavailable_metrics' => $calculation->unavailableMetrics,
                'input_snapshot' => $calculation->inputSnapshot,
                'calculated_at' => $calculation->calculatedAt,
            ]);

            return [
                'attribution' => $attribution->load([
                    'actor:id,name',
                    'analysis.listing:id,title',
                ]),
                'report' => $report,
                'created' => true,
            ];
        }, attempts: 3);
    }

    private function currentRealizedProfit(
        Organization $organization,
        OwnedProduct $product,
        string $realizedProfitId,
    ): RealizedProfit {
        $profit = RealizedProfit::query()
            ->forOrganization($organization)
            ->where('owned_product_id', $product->getKey())
            ->whereKey($realizedProfitId)
            ->with('costSnapshot.items')
            ->lockForUpdate()
            ->first();
        $latest = RealizedProfit::query()
            ->where('owned_product_id', $product->getKey())
            ->orderByDesc('run_number')
            ->lockForUpdate()
            ->first();

        if ($profit === null || $latest?->getKey() !== $profit->getKey()) {
            ApplicationValidation::fail(
                'realized_profit_id',
                ApplicationValidationCode::RealizedProfitNotCurrent,
            );
        }

        $purchase = ActualPurchase::query()
            ->where('owned_product_id', $product->getKey())
            ->orderByDesc('sequence')
            ->lockForUpdate()
            ->first();
        $costs = ActualCostSnapshot::query()
            ->where('owned_product_id', $product->getKey())
            ->orderByDesc('sequence')
            ->lockForUpdate()
            ->first();
        $sale = ActualSale::query()
            ->where('owned_product_id', $product->getKey())
            ->where('outcome_type', ActualSaleOutcomeType::Sold->value)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if (
            $purchase?->getKey() !== $profit->actual_purchase_id
            || $costs?->getKey() !== $profit->actual_cost_snapshot_id
            || $sale?->getKey() !== $profit->actual_sale_id
            || $costs->unknown_count > 0
            || $purchase->reporting_currency_code !== $profit->currency_code
            || $costs->reporting_currency_code !== $profit->currency_code
            || $sale->reporting_currency_code !== $profit->currency_code
        ) {
            ApplicationValidation::fail(
                'realized_profit_id',
                ApplicationValidationCode::RealizedEvidenceStale,
            );
        }

        return $profit;
    }

    /** @return array{Analysis, ProfitEstimate} */
    private function currentProfitEstimate(
        Organization $organization,
        string $analysisId,
        string $profitEstimateId,
    ): array {
        $analysis = Analysis::query()
            ->forOrganization($organization)
            ->whereKey($analysisId)
            ->where('analysis_type', AnalysisType::Buy->value)
            ->lockForUpdate()
            ->first();
        $estimate = ProfitEstimate::query()
            ->forOrganization($organization)
            ->where('analysis_id', $analysisId)
            ->whereKey($profitEstimateId)
            ->with(['items', 'currency'])
            ->lockForUpdate()
            ->first();
        $latest = ProfitEstimate::query()
            ->where('analysis_id', $analysisId)
            ->orderByDesc('run_number')
            ->lockForUpdate()
            ->first();

        if (
            $analysis === null
            || $estimate === null
            || $latest?->getKey() !== $estimate->getKey()
        ) {
            ApplicationValidation::fail(
                'profit_estimate_id',
                ApplicationValidationCode::ProfitEstimateNotCurrent,
            );
        }

        $analysis->loadMissing([
            'currentPriceEstimate',
            'currentRiskAssessment',
            'currentCostInput',
        ]);

        if (
            $estimate->status === ProfitEstimateStatus::NeedsInput
            || $estimate->unknown_count > 0
            || $estimate->purchase_price_minor === null
            || $estimate->additional_costs_minor === null
            || $estimate->expected_net_profit_minor === null
            || $analysis->currentPriceEstimate?->getKey()
                !== $estimate->price_estimate_id
            || $analysis->currentRiskAssessment?->getKey()
                !== $estimate->risk_assessment_id
            || $analysis->currentCostInput?->getKey()
                !== $estimate->cost_input_id
        ) {
            ApplicationValidation::fail(
                'profit_estimate_id',
                ApplicationValidationCode::ProfitEstimateEvidenceIncomplete,
            );
        }

        return [$analysis, $estimate];
    }

    private function guardHeads(
        ?string $expectedAttributionId,
        ?string $expectedReportId,
        ?OutcomeEstimateAttribution $currentAttribution,
        ?EstimateAccuracyReport $currentReport,
        ?string $correctionReason,
    ): void {
        if ($expectedAttributionId !== $currentAttribution?->getKey()) {
            throw new OutcomeTrackingConflictException(
                ApiErrorCode::EstimateAttributionStaleState,
                'expected_current_attribution_id',
                'The estimate attribution changed. Refresh before recording.',
            );
        }

        if ($expectedReportId !== $currentReport?->getKey()) {
            throw new OutcomeTrackingConflictException(
                ApiErrorCode::EstimateAccuracyReportStaleState,
                'expected_current_accuracy_report_id',
                'The estimate-accuracy report changed. Refresh before recording.',
            );
        }

        if ($currentAttribution !== null && $correctionReason === null) {
            ApplicationValidation::fail(
                'correction_reason',
                ApplicationValidationCode::CorrectionReasonRequired,
            );
        }
    }

    /**
     * @return array{
     *   attribution: OutcomeEstimateAttribution,
     *   report: EstimateAccuracyReport,
     *   created: false
     * }
     */
    private function idempotent(
        OutcomeEstimateAttribution $attribution,
        string $payloadHash,
    ): array {
        if (! hash_equals($attribution->payload_hash, $payloadHash)) {
            throw new OutcomeTrackingConflictException(
                ApiErrorCode::OutcomeIdempotencyConflict,
                'idempotency_key',
                'The idempotency key was already used with a different outcome command.',
            );
        }

        $report = $attribution->accuracyReport()->firstOrFail();

        return [
            'attribution' => $attribution->load([
                'actor:id,name',
                'analysis.listing:id,title',
            ]),
            'report' => $report,
            'created' => false,
        ];
    }

    /** @return array<string, int|null> */
    private function metricColumns(
        EstimateAccuracyReportData $calculation,
    ): array {
        $columns = [];

        foreach ($calculation->metrics as $name => $metric) {
            foreach ($metric as $suffix => $value) {
                $column = match ($suffix) {
                    'source_expected_minor' => (
                        "source_expected_{$name}_minor"
                    ),
                    'expected_minor' => "expected_{$name}_minor",
                    'actual_minor' => "actual_{$name}_minor",
                    default => "{$name}_{$suffix}",
                };
                $columns[$column] = $value;
            }
        }

        return $columns;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function payloadHash(
        Organization $organization,
        User $actor,
        OwnedProduct $product,
        array $attributes,
    ): string {
        return $this->hash([
            'organization_id' => $organization->getKey(),
            'owned_product_id' => $product->getKey(),
            'actor_user_id' => $actor->getKey(),
            'expected_current_attribution_id' => (
                $attributes['expected_current_attribution_id']
            ),
            'expected_current_accuracy_report_id' => (
                $attributes['expected_current_accuracy_report_id']
            ),
            'realized_profit_id' => $attributes['realized_profit_id'],
            'analysis_id' => $attributes['analysis_id'],
            'profit_estimate_id' => $attributes['profit_estimate_id'],
            'reason_code' => $attributes['reason_code'],
            'evidence_kind' => $attributes['evidence_kind'],
            'evidence_reference' => (
                $attributes['evidence_reference'] ?? null
            ),
            'correction_reason' => (
                $attributes['correction_reason'] ?? null
            ),
            'note' => $attributes['note'] ?? null,
        ]);
    }

    /** @param array<string, mixed> $value */
    private function hash(array $value): string
    {
        return hash('sha256', json_encode(
            $value,
            JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE,
        ));
    }
}
