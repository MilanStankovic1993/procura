<?php

namespace App\Actions\Analyses;

use App\Enums\Analyses\AnalysisStatus;
use App\Enums\Opportunity\ShippingMethod;
use App\Enums\Organizations\OrganizationPermission;
use App\Enums\Profit\ProfitEstimateStatus;
use App\Enums\Validation\ApplicationValidationCode;
use App\Models\Analysis;
use App\Models\ComparableSet;
use App\Models\CostInput;
use App\Models\Organization;
use App\Models\PriceEstimate;
use App\Models\ProfitEstimate;
use App\Models\RiskAssessment;
use App\Models\User;
use App\OpportunityAssessment\Contracts\DemandEvaluator;
use App\OpportunityAssessment\Contracts\LogisticsEvaluator;
use App\Support\Validation\ApplicationValidation;
use Illuminate\Support\Facades\DB;

class ConfirmOpportunityEvidence
{
    public function __construct(
        private readonly AnalysisAuthorizer $authorizer,
        private readonly RecordOpportunityInput $inputs,
        private readonly LogisticsEvaluator $logisticsEvaluator,
        private readonly DemandEvaluator $demandEvaluator,
        private readonly RecordOpportunityAssessment $assessments,
        private readonly OpportunityAssessmentResultProjection $projection,
        private readonly CalculateDealScore $dealScore,
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
                    ApplicationValidationCode::OpportunityEvidenceProcessingNotFinished,
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
            $costInput = CostInput::query()
                ->where('analysis_id', $lockedAnalysis->getKey())
                ->orderByDesc('run_number')
                ->lockForUpdate()
                ->first();
            $profitEstimate = ProfitEstimate::query()
                ->where('analysis_id', $lockedAnalysis->getKey())
                ->orderByDesc('run_number')
                ->lockForUpdate()
                ->first();
            $comparableSet = $priceEstimate === null
                ? null
                : ComparableSet::query()
                    ->lockForUpdate()
                    ->find($priceEstimate->comparable_set_id);

            if (
                $comparableSet === null
                || $priceEstimate === null
                || $riskAssessment === null
                || $costInput === null
                || $profitEstimate === null
                || $comparableSet->getKey()
                    !== $attributes['comparable_set_id']
                || $priceEstimate->getKey()
                    !== $attributes['price_estimate_id']
                || $riskAssessment->getKey()
                    !== $attributes['risk_assessment_id']
                || $costInput->getKey() !== $attributes['cost_input_id']
                || $profitEstimate->getKey()
                    !== $attributes['profit_estimate_id']
                || $priceEstimate->comparable_set_id
                    !== $comparableSet->getKey()
                || $riskAssessment->price_estimate_id
                    !== $priceEstimate->getKey()
                || $costInput->price_estimate_id
                    !== $priceEstimate->getKey()
                || $costInput->risk_assessment_id
                    !== $riskAssessment->getKey()
                || $profitEstimate->price_estimate_id
                    !== $priceEstimate->getKey()
                || $profitEstimate->risk_assessment_id
                    !== $riskAssessment->getKey()
                || $profitEstimate->cost_input_id !== $costInput->getKey()
            ) {
                ApplicationValidation::fail(
                    'analysis',
                    ApplicationValidationCode::OpportunityEvidenceStale,
                );
            }

            if (
                $profitEstimate->status === ProfitEstimateStatus::NeedsInput
                || $profitEstimate->expected_net_profit_minor === null
            ) {
                ApplicationValidation::fail(
                    'analysis',
                    ApplicationValidationCode::OpportunityProfitEstimateRequired,
                );
            }

            if (
                ($attributes['shipping_method'] ?? null)
                    === ShippingMethod::LocalPickup->value
                && ($attributes['pickup_available'] ?? null) !== true
            ) {
                ApplicationValidation::fail(
                    'pickup_available',
                    ApplicationValidationCode::OpportunityPickupConfirmationRequired,
                );
            }

            $inputResult = $this->inputs->record(
                $lockedAnalysis,
                $comparableSet,
                $priceEstimate,
                $riskAssessment,
                $costInput,
                $profitEstimate,
                $actor,
                $attributes,
            );
            $logisticsData = $this->logisticsEvaluator->evaluate(
                $lockedAnalysis,
                $profitEstimate,
                $inputResult['input'],
            );
            $demandData = $this->demandEvaluator->evaluate(
                $lockedAnalysis,
                $profitEstimate,
                $inputResult['input'],
            );
            $logisticsResult = $this->assessments->record(
                $lockedAnalysis,
                $profitEstimate,
                $inputResult['input'],
                $logisticsData,
            );
            $demandResult = $this->assessments->record(
                $lockedAnalysis,
                $profitEstimate,
                $inputResult['input'],
                $demandData,
            );
            $projected = $this->projection->apply(
                $lockedAnalysis,
                $inputResult['input'],
                $logisticsResult['assessment'],
                $demandResult['assessment'],
            );
            $dealScoreResult = $this->dealScore->calculate(
                $projected,
                $profitEstimate,
                $logisticsResult['assessment'],
                $demandResult['assessment'],
            );

            return [
                'analysis' => $dealScoreResult['analysis'],
                'created' => $inputResult['created']
                    || $logisticsResult['created']
                    || $demandResult['created']
                    || $dealScoreResult['created'],
            ];
        }, attempts: 3);
    }
}
