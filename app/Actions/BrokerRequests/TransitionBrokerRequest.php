<?php

namespace App\Actions\BrokerRequests;

use App\BrokerRequests\BrokerRequestInput;
use App\BrokerRequests\BrokerRequestOfferSnapshot;
use App\BrokerRequests\BrokerRequestSnapshot;
use App\BrokerRequests\Data\BrokerRequestWriteResult;
use App\Enums\BrokerRequests\BrokerRequestEventType;
use App\Enums\BrokerRequests\BrokerRequestOfferEventType;
use App\Enums\BrokerRequests\BrokerRequestOfferStatus;
use App\Enums\BrokerRequests\BrokerRequestStatus;
use App\Enums\Subscriptions\FeatureCode;
use App\Enums\Validation\ApplicationValidationCode;
use App\Models\BrokerRequest;
use App\Models\BrokerRequestEvent;
use App\Models\BrokerRequestOffer;
use App\Models\User;
use App\Subscriptions\SubscriptionUsageService;
use App\Support\Validation\ApplicationValidation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class TransitionBrokerRequest
{
    private const MAX_EVENT_HISTORY = 1000;

    public function __construct(
        private readonly SubscriptionUsageService $usage,
    ) {}

    public function submit(
        BrokerRequest $request,
        User $actor,
        string $expectedCurrentEventId,
        string $idempotencyKey,
    ): BrokerRequestWriteResult {
        return $this->transition(
            request: $request,
            actor: $actor,
            target: BrokerRequestStatus::Submitted,
            eventType: BrokerRequestEventType::Submitted,
            expectedCurrentEventId: $expectedCurrentEventId,
            idempotencyKey: $idempotencyKey,
            reasonCode: 'subject_submitted',
            operator: false,
        );
    }

    public function cancel(
        BrokerRequest $request,
        User $actor,
        string $expectedCurrentEventId,
        string $idempotencyKey,
    ): BrokerRequestWriteResult {
        return $this->transition(
            request: $request,
            actor: $actor,
            target: BrokerRequestStatus::Cancelled,
            eventType: BrokerRequestEventType::Cancelled,
            expectedCurrentEventId: $expectedCurrentEventId,
            idempotencyKey: $idempotencyKey,
            reasonCode: 'subject_cancelled',
            operator: false,
        );
    }

    public function operatorTransition(
        BrokerRequest $request,
        User $actor,
        BrokerRequestStatus $target,
        string $expectedCurrentEventId,
        string $idempotencyKey,
        string $reasonCode,
        string $evidenceReference,
    ): BrokerRequestWriteResult {
        if (! $actor->hasVerifiedEmail() || ! $actor->is_super_admin) {
            throw new AuthorizationException;
        }

        $eventType = match ($target) {
            BrokerRequestStatus::Reviewing => BrokerRequestEventType::ReviewStarted,
            BrokerRequestStatus::Searching => BrokerRequestEventType::SearchStarted,
            BrokerRequestStatus::Cancelled => BrokerRequestEventType::Cancelled,
            default => null,
        };

        if ($eventType === null) {
            ApplicationValidation::fail(
                'target_status',
                ApplicationValidationCode::BrokerRequestTransitionNotAllowed,
            );
        }

        if (trim($evidenceReference) === '') {
            ApplicationValidation::fail(
                'evidence_reference',
                ApplicationValidationCode::BrokerRequestOperatorEvidenceRequired,
            );
        }

        if (
            preg_match('/^[a-z0-9][a-z0-9._-]{2,63}$/', trim($reasonCode))
                !== 1
            || mb_strlen(trim($evidenceReference)) > 255
        ) {
            throw new InvalidArgumentException(
                'The reason code or evidence reference is invalid.',
            );
        }

        return $this->transition(
            request: $request,
            actor: $actor,
            target: $target,
            eventType: $eventType,
            expectedCurrentEventId: $expectedCurrentEventId,
            idempotencyKey: $idempotencyKey,
            reasonCode: trim($reasonCode),
            evidenceReference: trim($evidenceReference),
            operator: true,
        );
    }

    private function transition(
        BrokerRequest $request,
        User $actor,
        BrokerRequestStatus $target,
        BrokerRequestEventType $eventType,
        string $expectedCurrentEventId,
        string $idempotencyKey,
        string $reasonCode,
        ?string $evidenceReference = null,
        bool $operator = false,
    ): BrokerRequestWriteResult {
        $this->assertEnabled();

        if (
            ! Str::isUuid($idempotencyKey)
            || ! Str::isUlid($expectedCurrentEventId)
        ) {
            throw new InvalidArgumentException(
                'The broker-request event head or idempotency key is invalid.',
            );
        }

        $payloadHash = BrokerRequestInput::hash([
            'operation' => $eventType->value,
            'broker_request_id' => $request->getKey(),
            'expected_current_event_id' => $expectedCurrentEventId,
            'target_status' => $target->value,
            'reason_code' => $reasonCode,
            'evidence_reference' => $evidenceReference,
            'operator' => $operator,
        ]);

        return DB::transaction(function () use (
            $request,
            $actor,
            $target,
            $eventType,
            $expectedCurrentEventId,
            $idempotencyKey,
            $reasonCode,
            $evidenceReference,
            $operator,
            $payloadHash,
        ): BrokerRequestWriteResult {
            $locked = BrokerRequest::query()
                ->forOrganization($request->organization_id)
                ->lockForUpdate()
                ->findOrFail($request->getKey());
            $existing = BrokerRequestEvent::query()
                ->forOrganization($locked->organization_id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                $this->assertReplay($existing, $payloadHash);

                return new BrokerRequestWriteResult(
                    brokerRequest: $existing->brokerRequest()->firstOrFail(),
                    event: $existing,
                    created: false,
                );
            }

            if ($locked->current_event_id !== $expectedCurrentEventId) {
                ApplicationValidation::fail(
                    'expected_current_event_id',
                    ApplicationValidationCode::BrokerRequestStale,
                );
            }

            if (! $this->transitionAllowed($locked->status, $target, $operator)) {
                ApplicationValidation::fail(
                    'target_status',
                    ApplicationValidationCode::BrokerRequestTransitionNotAllowed,
                );
            }

            if ($locked->event_sequence >= self::MAX_EVENT_HISTORY) {
                ApplicationValidation::fail(
                    'broker_request',
                    ApplicationValidationCode::BrokerRequestHistoryLimit,
                );
            }

            if ($target === BrokerRequestStatus::Submitted) {
                $this->usage->consume(
                    $locked->organization()->firstOrFail(),
                    FeatureCode::MonthlyBrokerRequests,
                    1,
                    'broker-request-submit:'.$idempotencyKey,
                );
            }

            if ($target === BrokerRequestStatus::Cancelled) {
                $this->resolvePresentedOffers(
                    $locked,
                    $actor,
                    $idempotencyKey,
                    $payloadHash,
                );
            }

            $snapshot = BrokerRequestSnapshot::fromModel($locked, $target);
            $sequence = $locked->event_sequence + 1;
            $event = $locked->events()->create([
                'organization_id' => $locked->organization_id,
                'previous_event_id' => $locked->current_event_id,
                'actor_user_id' => $actor->getKey(),
                'sequence' => $sequence,
                'event_type' => $eventType,
                'from_status' => $locked->status,
                'to_status' => $target,
                'reason_code' => $reasonCode,
                'evidence_reference' => $evidenceReference,
                'idempotency_key' => $idempotencyKey,
                'payload_hash' => $payloadHash,
                'request_hash' => BrokerRequestInput::hash($snapshot),
                'request_snapshot' => $snapshot,
                'occurred_at' => now(),
            ]);
            $locked->forceFill([
                'status' => $target,
                'current_event_id' => $event->getKey(),
                'event_sequence' => $sequence,
                'submitted_at' => $target === BrokerRequestStatus::Submitted
                    ? now()
                    : $locked->submitted_at,
                'resolved_at' => $target->isTerminal()
                    ? now()
                    : null,
            ])->save();

            return new BrokerRequestWriteResult(
                brokerRequest: $locked,
                event: $event,
                created: true,
            );
        }, attempts: 3);
    }

    private function transitionAllowed(
        BrokerRequestStatus $from,
        BrokerRequestStatus $target,
        bool $operator,
    ): bool {
        if (! $operator) {
            return $target === BrokerRequestStatus::Submitted
                ? $from === BrokerRequestStatus::Draft
                : (
                    $target === BrokerRequestStatus::Cancelled
                    && $from->subjectCanCancel()
                );
        }

        return match ($from) {
            BrokerRequestStatus::Submitted => in_array(
                $target,
                [BrokerRequestStatus::Reviewing, BrokerRequestStatus::Cancelled],
                true,
            ),
            BrokerRequestStatus::Reviewing => in_array(
                $target,
                [BrokerRequestStatus::Searching, BrokerRequestStatus::Cancelled],
                true,
            ),
            BrokerRequestStatus::Searching => (
                $target === BrokerRequestStatus::Cancelled
            ),
            default => false,
        };
    }

    private function assertEnabled(): void
    {
        if (! config('broker.requests_enabled', true)) {
            ApplicationValidation::fail(
                'broker_request',
                ApplicationValidationCode::BrokerRequestsDisabled,
            );
        }
    }

    private function assertReplay(
        BrokerRequestEvent $existing,
        string $payloadHash,
    ): void {
        if (! hash_equals($existing->payload_hash, $payloadHash)) {
            ApplicationValidation::fail(
                'idempotency_key',
                ApplicationValidationCode::BrokerRequestIdempotencyConflict,
            );
        }
    }

    private function resolvePresentedOffers(
        BrokerRequest $request,
        User $actor,
        string $idempotencyKey,
        string $payloadHash,
    ): void {
        $offers = BrokerRequestOffer::query()
            ->forOrganization($request->organization_id)
            ->where('broker_request_id', $request->getKey())
            ->where('status', BrokerRequestOfferStatus::Presented)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($offers->contains(
            fn (BrokerRequestOffer $offer): bool => (
                $offer->event_sequence >= self::MAX_EVENT_HISTORY
            ),
        )) {
            ApplicationValidation::fail(
                'broker_offer',
                ApplicationValidationCode::BrokerOfferHistoryLimit,
            );
        }

        $occurredAt = now();

        foreach ($offers as $offer) {
            $snapshot = BrokerRequestOfferSnapshot::fromModel(
                $offer,
                BrokerRequestOfferStatus::NotSelected,
            );
            $event = $offer->events()->create([
                'organization_id' => $request->organization_id,
                'broker_request_id' => $request->getKey(),
                'previous_event_id' => $offer->current_event_id,
                'actor_user_id' => $actor->getKey(),
                'sequence' => $offer->event_sequence + 1,
                'event_type' => BrokerRequestOfferEventType::NotSelected,
                'from_status' => $offer->status,
                'to_status' => BrokerRequestOfferStatus::NotSelected,
                'reason_code' => 'request_cancelled',
                'evidence_reference' => null,
                'idempotency_key' => $idempotencyKey,
                'payload_hash' => $payloadHash,
                'offer_hash' => BrokerRequestInput::hash($snapshot),
                'offer_snapshot' => $snapshot,
                'occurred_at' => $occurredAt,
            ]);
            $offer->forceFill([
                'status' => BrokerRequestOfferStatus::NotSelected,
                'current_event_id' => $event->getKey(),
                'event_sequence' => $offer->event_sequence + 1,
                'resolved_at' => $occurredAt,
            ])->save();
        }
    }
}
