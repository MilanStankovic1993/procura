<?php

namespace App\Actions\Analyses;

use App\Analysis\Contracts\ConfiguredListingAiAnalyzer;
use App\Analysis\Contracts\ListingAiAnalyzer;
use App\Analysis\Data\AiAnalysisData;
use App\Analysis\Data\AnalysisInputData;
use App\Analysis\Governance\AnalysisProviderGovernor;
use App\Analysis\Metrics\AnalysisPipelineStageTimer;
use App\Analysis\Metrics\Contracts\AnalysisPipelineMetricRecorder;
use App\Analysis\Providers\FakeListingAiAnalyzer;
use App\ComparableSelection\Contracts\ComparableSelector;
use App\Enums\Analyses\AiAnalysisStatus;
use App\Enums\Analyses\AiValidationStatus;
use App\Enums\Analyses\AnalysisDispatchStatus;
use App\Enums\Analyses\AnalysisPipelineProviderScope;
use App\Enums\Analyses\AnalysisPipelineStage;
use App\Enums\Analyses\AnalysisStatus;
use App\Enums\Catalog\ProductMatchStatus;
use App\Enums\Comparables\ComparableSetStatus;
use App\Enums\Pricing\PriceEstimateStatus;
use App\Exceptions\AnalysisProviderException;
use App\Jobs\Monitoring\MatchListingSnapshot;
use App\Models\AiAnalysis;
use App\Models\Analysis;
use App\Models\AnalysisDispatch;
use App\Models\AnalysisProviderUsage;
use App\Models\ComparableSet;
use App\Models\PriceEstimate;
use App\Models\ProductMatch;
use App\Models\RiskAssessment;
use App\Pricing\Contracts\PriceEstimator;
use App\ProductMatching\Contracts\ProductMatcher;
use App\ProductMatching\Data\ProductMatchingInputData;
use App\ProductMatching\Providers\FakeCatalogProductMatcher;
use App\RiskAssessment\Contracts\RiskEvaluator;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

class RunBuyAnalysis
{
    public function __construct(
        private readonly ListingAiAnalyzer $provider,
        private readonly ProductMatcher $productMatcher,
        private readonly RecordProductMatch $productMatches,
        private readonly ComparableSelector $comparableSelector,
        private readonly RecordComparableSet $comparableSets,
        private readonly ComparableSelectionResultProjection $comparableProjection,
        private readonly PriceEstimator $priceEstimator,
        private readonly RecordPriceEstimate $priceEstimates,
        private readonly PriceEstimateResultProjection $priceProjection,
        private readonly RiskEvaluator $riskEvaluator,
        private readonly RecordRiskAssessment $riskAssessments,
        private readonly RiskAssessmentResultProjection $riskProjection,
        private readonly AnalysisProviderGovernor $providerGovernor,
        private readonly AnalysisPipelineMetricRecorder $pipelineMetrics,
    ) {}

    public function run(string $analysisId, string $dispatchId): void
    {
        $attempt = $this->begin($analysisId, $dispatchId);

        if ($attempt === null) {
            return;
        }

        [$analysis, $aiAnalysis] = $attempt;
        $timer = new AnalysisPipelineStageTimer;
        $providerScope = $this->providerScope();
        $providerUsage = null;
        $providerCompleted = false;
        $input = new AnalysisInputData(
            analysisId: $analysis->getKey(),
            inputHash: $analysis->request_hash,
            requestPayload: $analysis->request_payload,
        );

        try {
            if ($this->provider instanceof ConfiguredListingAiAnalyzer) {
                $providerUsage = $this->providerGovernor->reserve(
                    $analysis,
                    $aiAnalysis,
                    $this->provider,
                    $input,
                );
            }

            $result = $timer->measure(
                AnalysisPipelineStage::ProviderAnalysis,
                fn () => $this->provider->analyze($input),
            );

            if ($providerUsage instanceof AnalysisProviderUsage) {
                $this->providerGovernor->complete($providerUsage, $result);
                $providerCompleted = true;
            }

            $listing = $analysis->request_payload['listing'] ?? [];
            $marketScope = $analysis->request_payload['market_scope'] ?? [];
            $productMatch = $timer->measure(
                AnalysisPipelineStage::ProductMatching,
                function () use (
                    $analysis,
                    $aiAnalysis,
                    $listing,
                    $marketScope,
                    $result,
                ) {
                    $matchResult = $this->productMatcher->match(
                        new ProductMatchingInputData(
                            inputHash: $analysis->request_hash,
                            title: (string) (
                                $result->normalizedListing['title']
                                ?? $listing['title']
                                ?? ''
                            ),
                            description: isset($result->normalizedListing['description'])
                                ? (string) $result->normalizedListing['description']
                                : null,
                            marketCountryCodes: array_values(array_unique([
                                (string) (
                                    $marketScope['source_country_code']
                                    ?? $analysis->source_country_code
                                ),
                                (string) (
                                    $marketScope['target_country_code']
                                    ?? $analysis->target_country_code
                                ),
                            ])),
                            targetCountryCode: (string) (
                                $marketScope['target_country_code']
                                ?? $analysis->target_country_code
                            ),
                        ),
                    );

                    return $this->productMatches->record(
                        $analysis->getKey(),
                        $aiAnalysis->getKey(),
                        $matchResult,
                    );
                },
            );
            $comparableSet = null;
            $priceEstimate = null;
            $riskAssessment = null;

            if ($productMatch->status === ProductMatchStatus::Matched) {
                $comparableSet = $timer->measure(
                    AnalysisPipelineStage::ComparableSelection,
                    function () use ($analysis, $productMatch, $result) {
                        $selection = $this->comparableSelector->select(
                            $analysis,
                            $productMatch,
                            $result->normalizedListing,
                        );

                        return $this->comparableSets->record(
                            $analysis->getKey(),
                            $productMatch->getKey(),
                            $selection,
                        );
                    },
                );

                if ($comparableSet->status === ComparableSetStatus::Ready) {
                    $priceEstimate = $timer->measure(
                        AnalysisPipelineStage::PriceEstimation,
                        function () use ($analysis, $comparableSet, $result) {
                            $priceResult = $this->priceEstimator->estimate(
                                $analysis,
                                $comparableSet,
                                $result->normalizedListing,
                            );

                            return $this->priceEstimates->record(
                                $analysis->getKey(),
                                $comparableSet->getKey(),
                                $priceResult,
                            );
                        },
                    );

                    if (
                        $priceEstimate->status
                        !== PriceEstimateStatus::NeedsInput
                    ) {
                        $riskAssessment = $timer->measure(
                            AnalysisPipelineStage::RiskAssessment,
                            function () use (
                                $analysis,
                                $productMatch,
                                $comparableSet,
                                $priceEstimate,
                                $result,
                            ) {
                                $riskResult = $this->riskEvaluator->evaluate(
                                    $analysis,
                                    $productMatch,
                                    $comparableSet,
                                    $priceEstimate,
                                    $result->normalizedListing,
                                );

                                return $this->riskAssessments->record(
                                    $analysis->getKey(),
                                    $productMatch->getKey(),
                                    $comparableSet->getKey(),
                                    $priceEstimate->getKey(),
                                    $riskResult,
                                );
                            },
                        );
                    }
                }
            }

            $timer->measure(
                AnalysisPipelineStage::Finalization,
                fn () => $this->complete(
                    $analysis->getKey(),
                    $dispatchId,
                    $aiAnalysis->getKey(),
                    $productMatch->getKey(),
                    $comparableSet?->getKey(),
                    $priceEstimate?->getKey(),
                    $riskAssessment?->getKey(),
                    $result,
                ),
            );
            $this->recordPipelineMetric(
                $aiAnalysis,
                $analysis->pipeline_version,
                $providerScope,
                $timer,
            );
        } catch (Throwable $exception) {
            if (
                $providerUsage instanceof AnalysisProviderUsage
                && ! $providerCompleted
            ) {
                try {
                    $this->providerGovernor->fail($providerUsage, $exception);
                } catch (Throwable $governanceFailure) {
                    report($governanceFailure);
                }
            }

            try {
                $timer->measure(
                    AnalysisPipelineStage::Finalization,
                    fn () => $this->fail(
                        $analysis->getKey(),
                        $dispatchId,
                        $aiAnalysis->getKey(),
                        $aiAnalysis->attempt_number,
                        $exception,
                    ),
                );
            } finally {
                $this->recordPipelineMetric(
                    $aiAnalysis,
                    $analysis->pipeline_version,
                    $providerScope,
                    $timer,
                );
            }

            throw $exception;
        }
    }

    /** @return array{Analysis, AiAnalysis}|null */
    private function begin(string $analysisId, string $dispatchId): ?array
    {
        return DB::transaction(function () use ($analysisId, $dispatchId): ?array {
            $analysis = Analysis::query()->lockForUpdate()->findOrFail($analysisId);
            $dispatch = AnalysisDispatch::query()->lockForUpdate()->findOrFail($dispatchId);

            if ($dispatch->analysis_id !== $analysis->getKey()) {
                throw new LogicException('The dispatch does not belong to the requested analysis.');
            }

            if ($analysis->status->isTerminal() || $dispatch->status === AnalysisDispatchStatus::Completed) {
                return null;
            }

            if ($analysis->status === AnalysisStatus::Draft) {
                throw new LogicException('A draft analysis cannot be processed.');
            }

            $processingIsFresh = $analysis->status === AnalysisStatus::Processing
                && $analysis->processing_started_at?->gt(
                    now()->subSeconds((int) config('analyses.processing_timeout_seconds')),
                );

            if ($processingIsFresh) {
                return null;
            }

            $processingLeaseExpired = $analysis->status === AnalysisStatus::Processing;

            if ($processingLeaseExpired) {
                $expiredAt = now();
                $expiredMessage = 'The previous processing lease expired before completion.';

                AiAnalysis::query()
                    ->where('analysis_id', $analysis->getKey())
                    ->where('status', AiAnalysisStatus::Processing)
                    ->update([
                        'status' => AiAnalysisStatus::Failed,
                        'validation_status' => AiValidationStatus::Invalid,
                        'completed_at' => $expiredAt,
                        'error' => $expiredMessage,
                        'updated_at' => $expiredAt,
                    ]);
            }

            if ($analysis->processing_attempts >= $dispatch->max_processing_attempts) {
                if (! $processingLeaseExpired) {
                    return null;
                }

                $failedAt = now();
                $message = 'The analysis exhausted its processing attempts after a stale lease.';

                $analysis->update([
                    'status' => AnalysisStatus::Failed,
                    'failed_at' => $failedAt,
                    'next_retry_at' => null,
                    'last_error_code' => 'ProcessingLeaseExpired',
                    'last_error_message' => $message,
                ]);
                $dispatch->update([
                    'status' => AnalysisDispatchStatus::Failed,
                    'available_at' => null,
                    'failed_at' => $failedAt,
                    'last_error' => $message,
                ]);

                return null;
            }

            $attemptNumber = $analysis->processing_attempts + 1;
            $analysis->update([
                'status' => AnalysisStatus::Processing,
                'processing_attempts' => $attemptNumber,
                'processing_started_at' => now(),
                'next_retry_at' => null,
                'last_error_code' => null,
                'last_error_message' => null,
            ]);
            $dispatch->update([
                'status' => AnalysisDispatchStatus::Processing,
                'failed_at' => null,
                'last_error' => null,
            ]);
            $aiAnalysis = AiAnalysis::query()->create([
                'organization_id' => $analysis->organization_id,
                'analysis_id' => $analysis->getKey(),
                'attempt_number' => $attemptNumber,
                'status' => AiAnalysisStatus::Processing,
                'provider' => config('analyses.provider'),
                'model' => $this->provider instanceof ConfiguredListingAiAnalyzer
                    ? $this->provider->model()
                    : config('analyses.fake_model'),
                'prompt_version' => config('analyses.prompt_version'),
                'input_hash' => $analysis->request_hash,
                'input_snapshot' => $analysis->request_payload,
                'validation_status' => AiValidationStatus::Pending,
                'started_at' => now(),
            ]);

            return [$analysis->fresh(), $aiAnalysis];
        }, attempts: 3);
    }

    private function complete(
        string $analysisId,
        string $dispatchId,
        string $aiAnalysisId,
        string $productMatchId,
        ?string $comparableSetId,
        ?string $priceEstimateId,
        ?string $riskAssessmentId,
        AiAnalysisData $result,
    ): void {
        DB::transaction(function () use (
            $analysisId,
            $dispatchId,
            $aiAnalysisId,
            $productMatchId,
            $comparableSetId,
            $priceEstimateId,
            $riskAssessmentId,
            $result,
        ): void {
            $analysis = Analysis::query()->lockForUpdate()->findOrFail($analysisId);
            $dispatch = AnalysisDispatch::query()->lockForUpdate()->findOrFail($dispatchId);
            $aiAnalysis = AiAnalysis::query()->lockForUpdate()->findOrFail($aiAnalysisId);
            $productMatch = ProductMatch::query()
                ->lockForUpdate()
                ->findOrFail($productMatchId);
            $comparableSet = $comparableSetId === null
                ? null
                : ComparableSet::query()->lockForUpdate()->findOrFail($comparableSetId);
            $priceEstimate = $priceEstimateId === null
                ? null
                : PriceEstimate::query()
                    ->lockForUpdate()
                    ->findOrFail($priceEstimateId);
            $riskAssessment = $riskAssessmentId === null
                ? null
                : RiskAssessment::query()
                    ->lockForUpdate()
                    ->findOrFail($riskAssessmentId);

            if ($aiAnalysis->status !== AiAnalysisStatus::Processing) {
                return;
            }

            if (
                $productMatch->analysis_id !== $analysis->getKey()
                || $productMatch->ai_analysis_id !== $aiAnalysis->getKey()
            ) {
                throw new LogicException(
                    'The product match does not belong to the active AI attempt.',
                );
            }

            if (
                $comparableSet !== null
                && (
                    $comparableSet->analysis_id !== $analysis->getKey()
                    || $comparableSet->product_match_id !== $productMatch->getKey()
                )
            ) {
                throw new LogicException(
                    'The comparable set does not belong to the active product match.',
                );
            }

            if (
                $priceEstimate !== null
                && (
                    $comparableSet === null
                    || $priceEstimate->analysis_id !== $analysis->getKey()
                    || $priceEstimate->comparable_set_id
                        !== $comparableSet->getKey()
                )
            ) {
                throw new LogicException(
                    'The price estimate does not belong to the active comparable set.',
                );
            }

            if (
                $riskAssessment !== null
                && (
                    $comparableSet === null
                    || $priceEstimate === null
                    || $riskAssessment->analysis_id !== $analysis->getKey()
                    || $riskAssessment->product_match_id
                        !== $productMatch->getKey()
                    || $riskAssessment->comparable_set_id
                        !== $comparableSet->getKey()
                    || $riskAssessment->price_estimate_id
                        !== $priceEstimate->getKey()
                )
            ) {
                throw new LogicException(
                    'The risk assessment does not belong to the active evidence chain.',
                );
            }

            $resultData = $result->toArray();
            $matchNeedsInput = match ($productMatch->status) {
                ProductMatchStatus::Matched => [],
                ProductMatchStatus::Unmatched => ['model_uncertain'],
                ProductMatchStatus::ReviewRequired => in_array(
                    'target_market_variant_incompatible',
                    $productMatch->reason_codes,
                    true,
                )
                    ? ['region_incompatible_variant']
                    : ['model_confirmation_required'],
            };
            $needsInput = array_values(array_unique([
                ...($resultData['needs_input'] ?? []),
                ...$matchNeedsInput,
            ]));
            $resultPayload = [
                'schema_version' => 'buy-analysis-extraction-result:v8',
                'pipeline_version' => $analysis->pipeline_version,
                ...$resultData,
                'needs_input' => $needsInput,
                'product_match' => [
                    'id' => $productMatch->getKey(),
                    'status' => $productMatch->status->value,
                    'review_status' => $productMatch->review_status->value,
                    'method' => $productMatch->method,
                    'matcher_version' => $productMatch->matcher_version,
                    'confidence_basis_points' => $productMatch->confidence_basis_points,
                    'product_model_id' => $productMatch->product_model_id,
                    'product_variant_id' => $productMatch->product_variant_id,
                    'reason_codes' => $productMatch->reason_codes,
                ],
                'completed_steps' => [
                    'normalize_input',
                    $this->provider instanceof FakeListingAiAnalyzer
                        ? 'fake_ai_extraction'
                        : 'ai_extraction',
                    'product_matching',
                ],
                'pending_steps' => [
                    'comparable_selection',
                    'price_estimation',
                    'risk_assessment',
                    'cost_confirmation',
                    'profit_calculation',
                    'opportunity_evidence_confirmation',
                    'logistics_assessment',
                    'demand_assessment',
                    'deal_score',
                ],
            ];

            if ($comparableSet !== null) {
                $resultPayload = $this->comparableProjection->project(
                    $resultPayload,
                    $comparableSet,
                );
            }

            if ($priceEstimate !== null) {
                $resultPayload = $this->priceProjection->project(
                    $resultPayload,
                    $priceEstimate,
                );
            }

            if ($riskAssessment !== null) {
                $resultPayload = $this->riskProjection->project(
                    $resultPayload,
                    $riskAssessment,
                );
            }

            $status = $resultPayload['needs_input'] !== []
                ? AnalysisStatus::NeedsInput
                : AnalysisStatus::Completed;

            $aiAnalysis->update([
                'status' => AiAnalysisStatus::Completed,
                'result_json' => $resultData,
                'validation_status' => AiValidationStatus::Valid,
                'confidence_basis_points' => $result->confidenceBasisPoints,
                'tokens_in' => $result->tokensIn,
                'tokens_out' => $result->tokensOut,
                'estimated_cost_minor' => $result->estimatedCostMinor,
                'estimated_cost_currency' => $result->estimatedCostCurrency,
                'completed_at' => now(),
                'error' => null,
            ]);
            $analysis->update([
                'status' => $status,
                'result_payload' => $resultPayload,
                'finished_at' => now(),
                'failed_at' => null,
                'next_retry_at' => null,
                'last_error_code' => null,
                'last_error_message' => null,
            ]);
            $dispatch->update([
                'status' => AnalysisDispatchStatus::Completed,
                'completed_at' => now(),
                'failed_at' => null,
                'available_at' => null,
                'last_error' => null,
            ]);
            DB::afterCommit(
                static fn () => MatchListingSnapshot::dispatch(
                    $analysis->listing_snapshot_id,
                ),
            );
        }, attempts: 3);
    }

    private function fail(
        string $analysisId,
        string $dispatchId,
        string $aiAnalysisId,
        int $attemptNumber,
        Throwable $exception,
    ): void {
        DB::transaction(function () use (
            $analysisId,
            $dispatchId,
            $aiAnalysisId,
            $attemptNumber,
            $exception,
        ): void {
            $analysis = Analysis::query()->lockForUpdate()->findOrFail($analysisId);
            $dispatch = AnalysisDispatch::query()->lockForUpdate()->findOrFail($dispatchId);
            $aiAnalysis = AiAnalysis::query()->lockForUpdate()->findOrFail($aiAnalysisId);
            $delays = config('analyses.retry_delays_seconds');
            $canRetry = $attemptNumber < $dispatch->max_processing_attempts
                && ! $this->isTerminalProviderControlFailure($exception);
            $delay = (int) ($delays[$attemptNumber - 1] ?? end($delays) ?: 300);
            $retryAt = $canRetry ? now()->addSeconds($delay) : null;
            $message = str($exception->getMessage())->limit(10000)->toString();
            $errorCode = $exception instanceof AnalysisProviderException
                ? $exception->reasonCode
                : class_basename($exception);

            $aiAnalysis->update([
                'status' => AiAnalysisStatus::Failed,
                'validation_status' => AiValidationStatus::Invalid,
                'completed_at' => now(),
                'error' => $message,
            ]);
            $analysis->update([
                'status' => AnalysisStatus::Failed,
                'failed_at' => now(),
                'next_retry_at' => $retryAt,
                'last_error_code' => $errorCode,
                'last_error_message' => $message,
            ]);
            $dispatch->update([
                'status' => AnalysisDispatchStatus::Failed,
                'available_at' => $retryAt,
                'failed_at' => now(),
                'last_error' => $message,
            ]);
        }, attempts: 3);
    }

    private function providerScope(): AnalysisPipelineProviderScope
    {
        return $this->provider instanceof FakeListingAiAnalyzer
            || $this->productMatcher instanceof FakeCatalogProductMatcher
                ? AnalysisPipelineProviderScope::Rehearsal
                : AnalysisPipelineProviderScope::ProductionShaped;
    }

    private function isTerminalProviderControlFailure(Throwable $exception): bool
    {
        if (! $exception instanceof AnalysisProviderException) {
            return false;
        }

        return in_array($exception->reasonCode, [
            'analysis_provider_not_configured',
            'analysis_provider_governance_not_configured',
            'analysis_provider_actor_unavailable',
            'analysis_provider_task_budget_exceeded',
            'analysis_provider_global_budget_exhausted',
            'analysis_provider_organization_budget_exhausted',
            'analysis_provider_user_budget_exhausted',
            'analysis_provider_circuit_open',
            'analysis_provider_cost_currency_invalid',
            'analysis_provider_cost_reservation_invalid',
            'analysis_provider_cost_exceeded_reservation',
        ], true);
    }

    private function recordPipelineMetric(
        AiAnalysis $aiAnalysis,
        string $pipelineVersion,
        AnalysisPipelineProviderScope $providerScope,
        AnalysisPipelineStageTimer $timer,
    ): void {
        try {
            $this->pipelineMetrics->record(
                $aiAnalysis->getKey(),
                $aiAnalysis->attempt_number,
                $pipelineVersion,
                $providerScope,
                $timer,
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
