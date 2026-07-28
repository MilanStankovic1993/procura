<?php

namespace App\Actions\Analyses;

use App\Enums\Api\ApiErrorCode;
use App\Enums\BuyerDecisions\BuyerDecisionState;
use App\Enums\DealScoring\DealScoreStatus;
use App\Enums\Opportunity\OpportunityComponent;
use App\Enums\Organizations\OrganizationPermission;
use App\Enums\Validation\ApplicationValidationCode;
use App\Exceptions\BuyerDecisionConflictException;
use App\Models\Analysis;
use App\Models\BuyerDecisionEvent;
use App\Models\DealScore;
use App\Models\OpportunityAssessment;
use App\Models\OpportunityInput;
use App\Models\Organization;
use App\Models\PriceEstimate;
use App\Models\ProductMatch;
use App\Models\ProfitEstimate;
use App\Models\RiskAssessment;
use App\Models\User;
use App\Support\Validation\ApplicationValidation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use JsonException;

class RecordBuyerDecision
{
    public function __construct(
        private readonly AnalysisAuthorizer $authorizer,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{analysis: Analysis, event: BuyerDecisionEvent, created: bool}
     *
     * @throws JsonException
     */
    public function record(
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
            $payloadHash = $this->payloadHash(
                $organization,
                $actor,
                $lockedAnalysis,
                $attributes,
            );
            $existing = BuyerDecisionEvent::query()
                ->forOrganization($organization)
                ->where('idempotency_key', $attributes['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->payload_hash !== $payloadHash) {
                    throw new BuyerDecisionConflictException(
                        ApiErrorCode::BuyerDecisionIdempotencyConflict,
                        'idempotency_key',
                        'The idempotency key was already used with a different buyer decision command.',
                    );
                }

                return [
                    'analysis' => $lockedAnalysis,
                    'event' => $existing,
                    'created' => false,
                ];
            }

            $dealScore = DealScore::query()
                ->forOrganization($organization)
                ->where('analysis_id', $lockedAnalysis->getKey())
                ->orderByDesc('run_number')
                ->lockForUpdate()
                ->first();

            $this->guardCurrentAssessedScore(
                $organization,
                $lockedAnalysis,
                $dealScore,
                $attributes['deal_score_id'],
            );

            $current = BuyerDecisionEvent::query()
                ->forOrganization($organization)
                ->where('analysis_id', $lockedAnalysis->getKey())
                ->where('deal_score_id', $dealScore->getKey())
                ->orderByDesc('sequence')
                ->lockForUpdate()
                ->first();
            $expectedEventId = $attributes['expected_current_event_id'];
            $currentEventId = $current?->getKey();

            if ($expectedEventId !== $currentEventId) {
                throw new BuyerDecisionConflictException(
                    ApiErrorCode::BuyerDecisionStaleState,
                    'expected_current_event_id',
                    'The buyer decision changed. Refresh the analysis before recording another decision.',
                );
            }

            $nextState = BuyerDecisionState::from(
                $attributes['next_state'],
            );
            $priorState = $current?->next_state;

            if (! BuyerDecisionState::canTransition($priorState, $nextState)) {
                ApplicationValidation::fail(
                    'next_state',
                    ApplicationValidationCode::BuyerDecisionTransitionNotAllowed,
                );
            }

            $latestEvent = BuyerDecisionEvent::query()
                ->forOrganization($organization)
                ->where('analysis_id', $lockedAnalysis->getKey())
                ->orderByDesc('sequence')
                ->lockForUpdate()
                ->first();
            $nextSequence = ($latestEvent?->sequence ?? 0) + 1;

            if (
                $nextSequence > (int) config(
                    'buyer_decisions.maximum_events_per_analysis',
                )
            ) {
                ApplicationValidation::fail(
                    'analysis',
                    ApplicationValidationCode::BuyerDecisionHistoryLimit,
                );
            }

            try {
                $event = BuyerDecisionEvent::query()->create([
                    'organization_id' => $organization->getKey(),
                    'analysis_id' => $lockedAnalysis->getKey(),
                    'deal_score_id' => $dealScore->getKey(),
                    'previous_event_id' => $currentEventId,
                    'actor_user_id' => $actor->getKey(),
                    'sequence' => $nextSequence,
                    'prior_state' => $priorState,
                    'next_state' => $nextState,
                    'reason_code' => $attributes['reason_code'] ?? null,
                    'note' => $attributes['note'] ?? null,
                    'idempotency_key' => $attributes['idempotency_key'],
                    'payload_hash' => $payloadHash,
                    'decided_at' => now(),
                ]);
            } catch (QueryException $exception) {
                $collision = BuyerDecisionEvent::query()
                    ->forOrganization($organization)
                    ->where(
                        'idempotency_key',
                        $attributes['idempotency_key'],
                    )
                    ->first();

                if ($collision === null) {
                    throw $exception;
                }

                if ($collision->payload_hash !== $payloadHash) {
                    throw new BuyerDecisionConflictException(
                        ApiErrorCode::BuyerDecisionIdempotencyConflict,
                        'idempotency_key',
                        'The idempotency key was already used with a different buyer decision command.',
                    );
                }

                return [
                    'analysis' => $lockedAnalysis,
                    'event' => $collision,
                    'created' => false,
                ];
            }

            return [
                'analysis' => $lockedAnalysis,
                'event' => $event,
                'created' => true,
            ];
        }, attempts: 3);
    }

    private function guardCurrentAssessedScore(
        Organization $organization,
        Analysis $analysis,
        ?DealScore $dealScore,
        string $requestedDealScoreId,
    ): void {
        $analysisId = $analysis->getKey();
        $productMatch = ProductMatch::query()
            ->forOrganization($organization)
            ->where('analysis_id', $analysisId)
            ->orderByDesc('run_number')
            ->lockForUpdate()
            ->first();
        $priceEstimate = PriceEstimate::query()
            ->forOrganization($organization)
            ->where('analysis_id', $analysisId)
            ->orderByDesc('run_number')
            ->lockForUpdate()
            ->first();
        $riskAssessment = RiskAssessment::query()
            ->forOrganization($organization)
            ->where('analysis_id', $analysisId)
            ->orderByDesc('run_number')
            ->lockForUpdate()
            ->first();
        $profitEstimate = ProfitEstimate::query()
            ->forOrganization($organization)
            ->where('analysis_id', $analysisId)
            ->orderByDesc('run_number')
            ->lockForUpdate()
            ->first();
        $opportunityInput = OpportunityInput::query()
            ->forOrganization($organization)
            ->where('analysis_id', $analysisId)
            ->orderByDesc('run_number')
            ->lockForUpdate()
            ->first();
        $logisticsAssessment = $this->latestOpportunityAssessment(
            $organization,
            $analysisId,
            OpportunityComponent::Logistics,
        );
        $demandAssessment = $this->latestOpportunityAssessment(
            $organization,
            $analysisId,
            OpportunityComponent::Demand,
        );

        if (
            $dealScore === null
            || $dealScore->getKey() !== $requestedDealScoreId
            || $dealScore->status !== DealScoreStatus::Assessed
            || $dealScore->score === null
            || $productMatch === null
            || $priceEstimate === null
            || $riskAssessment === null
            || $profitEstimate === null
            || $opportunityInput === null
            || $logisticsAssessment === null
            || $demandAssessment === null
            || $dealScore->product_match_id !== $productMatch->getKey()
            || $dealScore->price_estimate_id !== $priceEstimate->getKey()
            || $dealScore->risk_assessment_id !== $riskAssessment->getKey()
            || $dealScore->profit_estimate_id !== $profitEstimate->getKey()
            || $dealScore->opportunity_input_id
                !== $opportunityInput->getKey()
            || $dealScore->logistics_assessment_id
                !== $logisticsAssessment->getKey()
            || $dealScore->demand_assessment_id
                !== $demandAssessment->getKey()
        ) {
            ApplicationValidation::fail(
                'deal_score_id',
                ApplicationValidationCode::BuyerDecisionEvidenceStale,
            );
        }
    }

    private function latestOpportunityAssessment(
        Organization $organization,
        string $analysisId,
        OpportunityComponent $component,
    ): ?OpportunityAssessment {
        return OpportunityAssessment::query()
            ->forOrganization($organization)
            ->where('analysis_id', $analysisId)
            ->where('component', $component->value)
            ->orderByDesc('run_number')
            ->lockForUpdate()
            ->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws JsonException
     */
    private function payloadHash(
        Organization $organization,
        User $actor,
        Analysis $analysis,
        array $attributes,
    ): string {
        return hash('sha256', json_encode([
            'organization_id' => $organization->getKey(),
            'analysis_id' => $analysis->getKey(),
            'deal_score_id' => $attributes['deal_score_id'],
            'actor_user_id' => $actor->getKey(),
            'expected_current_event_id' => (
                $attributes['expected_current_event_id']
            ),
            'next_state' => $attributes['next_state'],
            'reason_code' => $attributes['reason_code'] ?? null,
            'note' => $attributes['note'] ?? null,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
