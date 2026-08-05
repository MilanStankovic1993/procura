<?php

namespace App\Actions\BrokerRequests;

use App\BrokerRequests\BrokerRequestInput;
use App\BrokerRequests\BrokerRequestOfferInput;
use App\BrokerRequests\BrokerRequestOfferSnapshot;
use App\BrokerRequests\BrokerRequestSnapshot;
use App\BrokerRequests\Data\BrokerRequestOfferWriteResult;
use App\Enums\BrokerRequests\BrokerRequestEventType;
use App\Enums\BrokerRequests\BrokerRequestOfferEventType;
use App\Enums\BrokerRequests\BrokerRequestOfferStatus;
use App\Enums\BrokerRequests\BrokerRequestStatus;
use App\Enums\Validation\ApplicationValidationCode;
use App\Models\BrokerRequest;
use App\Models\BrokerRequestEvent;
use App\Models\BrokerRequestOfferEvent;
use App\Models\User;
use App\Support\Validation\ApplicationValidation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class PresentBrokerRequestOffer
{
    private const MAX_OFFERS_PER_REQUEST = 100;

    private const MAX_REQUEST_EVENT_HISTORY = 1000;

    public function __construct(
        private readonly BrokerRequestOfferInput $input,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function execute(
        BrokerRequest $request,
        User $actor,
        string $expectedRequestEventId,
        string $idempotencyKey,
        string $evidenceReference,
        array $input,
    ): BrokerRequestOfferWriteResult {
        $this->assertEnabled();
        $this->assertOperator($actor);

        $evidenceReference = trim($evidenceReference);

        if (
            $evidenceReference === ''
            || mb_strlen($evidenceReference) > 255
        ) {
            ApplicationValidation::fail(
                'evidence_reference',
                ApplicationValidationCode::BrokerOfferOperatorEvidenceRequired,
            );
        }

        if (
            ! Str::isUlid($expectedRequestEventId)
            || ! Str::isUuid($idempotencyKey)
        ) {
            throw new InvalidArgumentException(
                'The broker-request head or idempotency key is invalid.',
            );
        }

        $validated = $this->input->normalizeAndValidate($input);
        $payloadHash = BrokerRequestInput::hash([
            'operation' => BrokerRequestEventType::OfferPresented->value,
            'broker_request_id' => $request->getKey(),
            'expected_request_event_id' => $expectedRequestEventId,
            'evidence_reference' => $evidenceReference,
            'offer' => $validated,
        ]);

        return DB::transaction(function () use (
            $request,
            $actor,
            $expectedRequestEventId,
            $idempotencyKey,
            $evidenceReference,
            $validated,
            $payloadHash,
        ): BrokerRequestOfferWriteResult {
            $locked = BrokerRequest::query()
                ->forOrganization($request->organization_id)
                ->lockForUpdate()
                ->findOrFail($request->getKey());
            $existingRequestEvent = BrokerRequestEvent::query()
                ->forOrganization($locked->organization_id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingRequestEvent !== null) {
                $this->assertReplay($existingRequestEvent, $payloadHash);
                $offerEvent = BrokerRequestOfferEvent::query()
                    ->forOrganization($locked->organization_id)
                    ->where('broker_request_id', $locked->getKey())
                    ->where('idempotency_key', $idempotencyKey)
                    ->where(
                        'event_type',
                        BrokerRequestOfferEventType::Presented,
                    )
                    ->firstOrFail();

                return new BrokerRequestOfferWriteResult(
                    brokerRequest: $existingRequestEvent
                        ->brokerRequest()
                        ->firstOrFail(),
                    offer: $offerEvent->brokerRequestOffer()->firstOrFail(),
                    requestEvent: $existingRequestEvent,
                    offerEvent: $offerEvent,
                    created: false,
                );
            }

            if ($locked->current_event_id !== $expectedRequestEventId) {
                ApplicationValidation::fail(
                    'expected_request_event_id',
                    ApplicationValidationCode::BrokerRequestStale,
                );
            }

            if (! in_array($locked->status, [
                BrokerRequestStatus::Searching,
                BrokerRequestStatus::OffersAvailable,
            ], true)) {
                ApplicationValidation::fail(
                    'broker_request',
                    ApplicationValidationCode::BrokerOfferRequestStateInvalid,
                );
            }

            if ($locked->event_sequence >= self::MAX_REQUEST_EVENT_HISTORY) {
                ApplicationValidation::fail(
                    'broker_request',
                    ApplicationValidationCode::BrokerRequestHistoryLimit,
                );
            }

            if ($locked->offers()->count() >= self::MAX_OFFERS_PER_REQUEST) {
                ApplicationValidation::fail(
                    'broker_request',
                    ApplicationValidationCode::BrokerOfferLimit,
                );
            }

            $occurredAt = now();
            $offer = $locked->offers()->create([
                'organization_id' => $locked->organization_id,
                'presented_by_user_id' => $actor->getKey(),
                'status' => BrokerRequestOfferStatus::Presented,
                ...$validated,
                'source_request_event_id' => $expectedRequestEventId,
                'event_sequence' => 0,
                'presented_at' => $occurredAt,
            ]);
            $offerSnapshot = BrokerRequestOfferSnapshot::fromModel($offer);
            $offerEvent = $offer->events()->create([
                'organization_id' => $locked->organization_id,
                'broker_request_id' => $locked->getKey(),
                'previous_event_id' => null,
                'actor_user_id' => $actor->getKey(),
                'sequence' => 1,
                'event_type' => BrokerRequestOfferEventType::Presented,
                'from_status' => null,
                'to_status' => BrokerRequestOfferStatus::Presented,
                'reason_code' => 'operator_offer_presented',
                'evidence_reference' => $evidenceReference,
                'idempotency_key' => $idempotencyKey,
                'payload_hash' => $payloadHash,
                'offer_hash' => BrokerRequestInput::hash($offerSnapshot),
                'offer_snapshot' => $offerSnapshot,
                'occurred_at' => $occurredAt,
            ]);
            $offer->forceFill([
                'current_event_id' => $offerEvent->getKey(),
                'event_sequence' => 1,
            ])->save();

            $target = BrokerRequestStatus::OffersAvailable;
            $requestSnapshot = BrokerRequestSnapshot::fromModel(
                $locked,
                $target,
            );
            $requestSequence = $locked->event_sequence + 1;
            $requestEvent = $locked->events()->create([
                'organization_id' => $locked->organization_id,
                'previous_event_id' => $locked->current_event_id,
                'actor_user_id' => $actor->getKey(),
                'sequence' => $requestSequence,
                'event_type' => BrokerRequestEventType::OfferPresented,
                'from_status' => $locked->status,
                'to_status' => $target,
                'reason_code' => 'operator_offer_presented',
                'evidence_reference' => $evidenceReference,
                'idempotency_key' => $idempotencyKey,
                'payload_hash' => $payloadHash,
                'request_hash' => BrokerRequestInput::hash($requestSnapshot),
                'request_snapshot' => $requestSnapshot,
                'occurred_at' => $occurredAt,
            ]);
            $locked->forceFill([
                'status' => $target,
                'current_event_id' => $requestEvent->getKey(),
                'event_sequence' => $requestSequence,
            ])->save();

            return new BrokerRequestOfferWriteResult(
                brokerRequest: $locked,
                offer: $offer,
                requestEvent: $requestEvent,
                offerEvent: $offerEvent,
                created: true,
            );
        }, attempts: 3);
    }

    private function assertEnabled(): void
    {
        if (! config('broker.offers_enabled', false)) {
            ApplicationValidation::fail(
                'broker_offer',
                ApplicationValidationCode::BrokerOffersDisabled,
            );
        }
    }

    private function assertOperator(User $actor): void
    {
        if (! $actor->hasVerifiedEmail() || ! $actor->is_super_admin) {
            throw new AuthorizationException;
        }
    }

    private function assertReplay(
        BrokerRequestEvent $existing,
        string $payloadHash,
    ): void {
        if (
            $existing->event_type !== BrokerRequestEventType::OfferPresented
            || ! hash_equals($existing->payload_hash, $payloadHash)
        ) {
            ApplicationValidation::fail(
                'idempotency_key',
                ApplicationValidationCode::BrokerOfferIdempotencyConflict,
            );
        }
    }
}
