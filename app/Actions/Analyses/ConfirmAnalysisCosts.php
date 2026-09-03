<?php

namespace App\Actions\Analyses;

use App\Enums\Analyses\AnalysisStatus;
use App\Enums\Organizations\OrganizationPermission;
use App\Enums\Pricing\PriceEstimateStatus;
use App\Enums\Validation\ApplicationValidationCode;
use App\Models\Analysis;
use App\Models\Organization;
use App\Models\PriceEstimate;
use App\Models\RiskAssessment;
use App\Models\User;
use App\ProfitCalculation\Contracts\ProfitCalculator;
use App\Support\Validation\ApplicationValidation;
use Illuminate\Support\Facades\DB;

class ConfirmAnalysisCosts
{
    public function __construct(
        private readonly AnalysisAuthorizer $authorizer,
        private readonly RecordCostInput $costInputs,
        private readonly ProfitCalculator $calculator,
        private readonly RecordProfitEstimate $profitEstimates,
        private readonly ProfitEstimateResultProjection $projection,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{analysis: Analysis, created: bool}
     */
    public function confirm(
        Organization $organization,
        User $actor,
        Analysis $analysis,
        array $attributes,
    ): array {
        return DB::transaction(function () use (
            $organization,
            $actor,
            $analysis,
            $attributes,
        ): array {
            $this->authorizer->authorize(
                $organization,
                $actor,
                OrganizationPermission::ManageAnalyses,
                lockForUpdate: true,
            );
            $lockedAnalysis = Analysis::query()
                ->forOrganization($organization)
                ->lockForUpdate()
                ->findOrFail($analysis->getKey());

            if (! in_array($lockedAnalysis->status, [
                AnalysisStatus::NeedsInput,
                AnalysisStatus::Completed,
            ], true)) {
                ApplicationValidation::fail(
                    'analysis',
                    ApplicationValidationCode::AnalysisCostsProcessingNotFinished,
                );
            }

            $priceEstimate = PriceEstimate::query()
                ->where('analysis_id', $lockedAnalysis->getKey())
                ->orderByDesc('run_number')
                ->lockForUpdate()
                ->first();
            $riskAssessment = RiskAssessment::query()
                ->where('analysis_id', $lockedAnalysis->getKey())
                ->orderByDesc('run_number')
                ->lockForUpdate()
                ->first();

            if (
                $priceEstimate === null
                || $riskAssessment === null
                || $priceEstimate->getKey() !== $attributes['price_estimate_id']
                || $riskAssessment->getKey() !== $attributes['risk_assessment_id']
                || $riskAssessment->price_estimate_id !== $priceEstimate->getKey()
            ) {
                ApplicationValidation::fail(
                    'analysis',
                    ApplicationValidationCode::AnalysisCostsEvidenceStale,
                );
            }

            if (
                $priceEstimate->status === PriceEstimateStatus::NeedsInput
                || $priceEstimate->estimate_minor === null
            ) {
                ApplicationValidation::fail(
                    'analysis',
                    ApplicationValidationCode::AnalysisCostsPriceEstimateRequired,
                );
            }

            $currencyCode = strtoupper((string) $attributes['currency_code']);

            if ($currencyCode !== $priceEstimate->target_currency_code) {
                ApplicationValidation::fail(
                    'currency_code',
                    ApplicationValidationCode::AnalysisCostsCurrencyMismatch,
                );
            }

            $costResult = $this->costInputs->record(
                $lockedAnalysis,
                $priceEstimate,
                $riskAssessment,
                $actor,
                [
                    ...$attributes,
                    'currency_code' => $currencyCode,
                ],
            );
            $profitData = $this->calculator->calculate(
                $lockedAnalysis,
                $priceEstimate,
                $riskAssessment,
                $costResult['input'],
            );
            $profitResult = $this->profitEstimates->record(
                $lockedAnalysis,
                $priceEstimate,
                $riskAssessment,
                $costResult['input'],
                $profitData,
            );
            $projected = $this->projection->apply(
                $lockedAnalysis,
                $costResult['input'],
                $profitResult['estimate'],
            );

            return [
                'analysis' => $projected,
                'created' => $profitResult['created'],
            ];
        }, attempts: 3);
    }
}
