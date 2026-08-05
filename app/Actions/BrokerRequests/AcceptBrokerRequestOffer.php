<?php

namespace App\Actions\BrokerRequests;

use App\BrokerRequests\BrokerCommissionCalculator;
use App\BrokerRequests\BrokerCommissionSnapshot;
use App\BrokerRequests\BrokerRequestInput;
use App\BrokerRequests\BrokerRequestOfferSnapshot;
use App\BrokerRequests\BrokerRequestSnapshot;
use App\BrokerRequests\BrokerTransactionSnapshot;
use App\BrokerRequests\Data\BrokerRequestOfferWriteResult;
use App\Enums\BrokerRequests\BrokerCommissionEventType;
use App\Enums\BrokerRequests\BrokerCommissionStatus;
use App\Enums\BrokerRequests\BrokerRequestEventType;
use App\Enums\BrokerRequests\BrokerRequestOfferEventType;
use App\Enums\BrokerRequests\BrokerRequestOfferStatus;
use App\Enums\BrokerRequests\BrokerRequestStatus;
use App\Enums\BrokerRequests\BrokerTransactionEventType;
use App\Enums\BrokerRequests\BrokerTransactionStatus;
use App\Enums\Organizations\OrganizationPermission;
use App\Enums\Validation\ApplicationValidationCode;
use App\Models\BrokerCommission;
use App\Models\BrokerRequest;
use App\Models\BrokerRequestEvent;
use App\Models\BrokerRequestOffer;
use App\Models\BrokerRequestOfferEvent;
use App\Models\BrokerTransaction;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Support\Validation\ApplicationValidation;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class AcceptBrokerRequestOffer
{
    private const MAX_EVENT_HISTORY = 1000;

    public function __construct(
        private readonly BrokerCommissionCalculator $commissionCalculator,
    ) {}

    public function execute(
        BrokerRequest $request,
        BrokerRequestOffer $offer,
        User $actor,
        string $expectedRequestEventId,
        string $expectedOfferEventId,
        string $idempotencyKey,
    ): BrokerRequestOfferWriteResult {
        $this->assertEnabled();
        $this->assertActorMayManage($request, $actor);

        if (
            ! Str::isUlid($expectedRequestEventId)
            || ! Str::isUlid($expectedOfferEventId)
            || ! Str::isUuid($idempotencyKey)
        ) {
            throw new InvalidArgumentException(
                'The request/offer heads or idempotency key are invalid.',
            );
        }

        $payloadHash = BrokerRequestInput::hash([
            'operation' => BrokerRequestEventType::OfferAccepted->value,
            'broker_request_id' => $request->getKey(),
            'broker_request_offer_id' => $offer->getKey(),
            'expected_request_event_id' => $expectedRequestEventId,
            'expected_offer_event_id' => $expectedOfferEventId,
        ]);

        return DB::transaction(function () use (
            $request,
            $offer,
            $actor,
            $expectedRequestEventId,
            $expectedOfferEventId,
            $idempotencyKey,
            $payloadHash,
        ): BrokerRequestOfferWriteResult {
            $lockedRequest = BrokerRequest::query()
                ->forOrganization($request->organization_id)
                ->lockForUpdate()
                ->findOrFail($request->getKey());
            $existingRequestEvent = BrokerRequestEvent::query()
                ->forOrganization($lockedRequest->organization_id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingRequestEvent !== null) {
                $this->assertReplay($existingRequestEvent, $payloadHash);
                $offerEvent = BrokerRequestOfferEvent::query()
                    ->forOrganization($lockedRequest->organization_id)
                    ->where('broker_request_offer_id', $offer->getKey())
                    ->where('idempotency_key', $idempotencyKey)
                    ->where(
                        'event_type',
                        BrokerRequestOfferEventType::Accepted,
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
                    transaction: BrokerTransaction::query()
                        ->where(
                            'broker_request_id',
                            $existingRequestEvent->broker_request_id,
                        )
                        ->firstOrFail(),
                    commission: BrokerCommission::query()
                        ->whereHas(
                            'brokerTransaction',
                            fn ($query) => $query->where(
                                'broker_request_id',
                                $existingRequestEvent->broker_request_id,
                            ),
                        )
                        ->firstOrFail(),
                );
            }

            /** @var Collection<int, BrokerRequestOffer> $offers */
            $offers = BrokerRequestOffer::query()
                ->forOrganization($lockedRequest->organization_id)
                ->where('broker_request_id', $lockedRequest->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $selected = $offers->first(
                fn (BrokerRequestOffer $candidate): bool => (
                    $candidate->getKey() === $offer->getKey()
                ),
            );

            if ($selected === null) {
                abort(404);
            }

            if ($lockedRequest->current_event_id !== $expectedRequestEventId) {
                ApplicationValidation::fail(
                    'expected_request_event_id',
                    ApplicationValidationCode::BrokerRequestStale,
                );
            }

            if ($selected->current_event_id !== $expectedOfferEventId) {
                ApplicationValidation::fail(
                    'expected_offer_event_id',
                    ApplicationValidationCode::BrokerOfferStale,
                );
            }

            if (
                $lockedRequest->status !== BrokerRequestStatus::OffersAvailable
            ) {
                ApplicationValidation::fail(
                    'broker_request',
                    ApplicationValidationCode::BrokerOfferRequestStateInvalid,
                );
            }

            if ($selected->status !== BrokerRequestOfferStatus::Presented) {
                ApplicationValidation::fail(
                    'broker_offer',
                    ApplicationValidationCode::BrokerOfferTransitionNotAllowed,
                );
            }

            if ($selected->valid_until->isPast()) {
                ApplicationValidation::fail(
                    'broker_offer',
                    ApplicationValidationCode::BrokerOfferExpired,
                );
            }

            $this->assertCommissionTerms($selected);

            if ($lockedRequest->event_sequence >= self::MAX_EVENT_HISTORY) {
                ApplicationValidation::fail(
                    'broker_request',
                    ApplicationValidationCode::BrokerRequestHistoryLimit,
                );
            }

            $presented = $offers->filter(
                fn (BrokerRequestOffer $candidate): bool => (
                    $candidate->status === BrokerRequestOfferStatus::Presented
                ),
            );

            if ($presented->contains(
                fn (BrokerRequestOffer $candidate): bool => (
                    $candidate->event_sequence >= self::MAX_EVENT_HISTORY
                ),
            )) {
                ApplicationValidation::fail(
                    'broker_offer',
                    ApplicationValidationCode::BrokerOfferHistoryLimit,
                );
            }

            $occurredAt = now();
            $selectedEvent = null;

            foreach ($presented as $candidate) {
                $isSelected = $candidate->is($selected);
                $target = $isSelected
                    ? BrokerRequestOfferStatus::Accepted
                    : BrokerRequestOfferStatus::NotSelected;
                $eventType = $isSelected
                    ? BrokerRequestOfferEventType::Accepted
                    : BrokerRequestOfferEventType::NotSelected;
                $snapshot = BrokerRequestOfferSnapshot::fromModel(
                    $candidate,
                    $target,
                );
                $event = $candidate->events()->create([
                    'organization_id' => $lockedRequest->organization_id,
                    'broker_request_id' => $lockedRequest->getKey(),
                    'previous_event_id' => $candidate->current_event_id,
                    'actor_user_id' => $actor->getKey(),
                    'sequence' => $candidate->event_sequence + 1,
                    'event_type' => $eventType,
                    'from_status' => $candidate->status,
                    'to_status' => $target,
                    'reason_code' => $isSelected
                        ? 'subject_offer_accepted'
                        : 'alternative_offer_not_selected',
                    'evidence_reference' => null,
                    'idempotency_key' => $idempotencyKey,
                    'payload_hash' => $payloadHash,
                    'offer_hash' => BrokerRequestInput::hash($snapshot),
                    'offer_snapshot' => $snapshot,
                    'occurred_at' => $occurredAt,
                ]);
                $candidate->forceFill([
                    'status' => $target,
                    'current_event_id' => $event->getKey(),
                    'event_sequence' => $candidate->event_sequence + 1,
                    'accepted_at' => $isSelected ? $occurredAt : null,
                    'resolved_at' => $occurredAt,
                ])->save();

                if ($isSelected) {
                    $selectedEvent = $event;
                }
            }

            if (! $selectedEvent instanceof BrokerRequestOfferEvent) {
                throw new InvalidArgumentException(
                    'The selected broker offer was not transitionable.',
                );
            }

            $requestSnapshot = BrokerRequestSnapshot::fromModel(
                $lockedRequest,
                BrokerRequestStatus::Accepted,
            );
            $requestEvent = $lockedRequest->events()->create([
                'organization_id' => $lockedRequest->organization_id,
                'previous_event_id' => $lockedRequest->current_event_id,
                'actor_user_id' => $actor->getKey(),
                'sequence' => $lockedRequest->event_sequence + 1,
                'event_type' => BrokerRequestEventType::OfferAccepted,
                'from_status' => $lockedRequest->status,
                'to_status' => BrokerRequestStatus::Accepted,
                'reason_code' => 'subject_offer_accepted',
                'evidence_reference' => null,
                'idempotency_key' => $idempotencyKey,
                'payload_hash' => $payloadHash,
                'request_hash' => BrokerRequestInput::hash($requestSnapshot),
                'request_snapshot' => $requestSnapshot,
                'occurred_at' => $occurredAt,
            ]);
            $lockedRequest->forceFill([
                'status' => BrokerRequestStatus::Accepted,
                'current_event_id' => $requestEvent->getKey(),
                'event_sequence' => $lockedRequest->event_sequence + 1,
            ])->save();
            [$transaction, $commission] = $this->openTransaction(
                request: $lockedRequest,
                offer: $selected,
                requestEvent: $requestEvent,
                offerEvent: $selectedEvent,
                actor: $actor,
                idempotencyKey: $idempotencyKey,
                payloadHash: $payloadHash,
                occurredAt: $occurredAt,
            );

            return new BrokerRequestOfferWriteResult(
                brokerRequest: $lockedRequest,
                offer: $selected,
                requestEvent: $requestEvent,
                offerEvent: $selectedEvent,
                created: true,
                transaction: $transaction,
                commission: $commission,
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

        if (! config('broker.transactions_enabled', false)) {
            ApplicationValidation::fail(
                'broker_transaction',
                ApplicationValidationCode::BrokerTransactionsDisabled,
            );
        }
    }

    private function assertActorMayManage(
        BrokerRequest $request,
        User $actor,
    ): void {
        $allowed = OrganizationMembership::query()
            ->where('organization_id', $request->organization_id)
            ->where('user_id', $actor->getKey())
            ->first()
            ?->role
            ->allows(OrganizationPermission::ManageBrokerRequests) ?? false;

        if (! $allowed) {
            throw new AuthorizationException;
        }
    }

    private function assertCommissionTerms(BrokerRequestOffer $offer): void
    {
        $expected = $this->commissionCalculator->calculate(
            $offer->total_minor,
            $offer->commission_rule_version,
            $offer->commission_rate_basis_points,
            ApplicationValidationCode::BrokerCommissionTermsInvalid,
        );

        foreach ($expected as $field => $value) {
            if ($offer->getAttribute($field) !== $value) {
                ApplicationValidation::fail(
                    'broker_offer',
                    ApplicationValidationCode::BrokerCommissionTermsInvalid,
                );
            }
        }
    }

    private function assertReplay(
        BrokerRequestEvent $existing,
        string $payloadHash,
    ): void {
        if (
            $existing->event_type !== BrokerRequestEventType::OfferAccepted
            || ! hash_equals($existing->payload_hash, $payloadHash)
        ) {
            ApplicationValidation::fail(
                'idempotency_key',
                ApplicationValidationCode::BrokerOfferIdempotencyConflict,
            );
        }
    }

    /**
     * @return array{BrokerTransaction, BrokerCommission}
     */
    private function openTransaction(
        BrokerRequest $request,
        BrokerRequestOffer $offer,
        BrokerRequestEvent $requestEvent,
        BrokerRequestOfferEvent $offerEvent,
        User $actor,
        string $idempotencyKey,
        string $payloadHash,
        CarbonInterface $occurredAt,
    ): array {
        $transaction = BrokerTransaction::query()->create([
            'organization_id' => $request->organization_id,
            'broker_request_id' => $request->getKey(),
            'broker_request_offer_id' => $offer->getKey(),
            'opened_by_user_id' => $actor->getKey(),
            'status' => BrokerTransactionStatus::AwaitingPayment,
            'supplier_total_minor' => $offer->total_minor,
            'commission_amount_minor' => $offer->commission_amount_minor,
            'payable_total_minor' => $offer->payable_total_minor,
            'currency_code' => $offer->currency_code,
            'source_request_event_id' => $requestEvent->getKey(),
            'source_offer_event_id' => $offerEvent->getKey(),
            'event_sequence' => 0,
            'opened_at' => $occurredAt,
        ]);
        $transactionSnapshot = BrokerTransactionSnapshot::fromModel(
            $transaction,
        );
        $transactionEvent = $transaction->events()->create([
            'organization_id' => $request->organization_id,
            'broker_request_id' => $request->getKey(),
            'broker_request_offer_id' => $offer->getKey(),
            'previous_event_id' => null,
            'actor_user_id' => $actor->getKey(),
            'sequence' => 1,
            'event_type' => BrokerTransactionEventType::Opened,
            'from_status' => null,
            'to_status' => BrokerTransactionStatus::AwaitingPayment,
            'reason_code' => 'subject_offer_accepted',
            'evidence_reference' => null,
            'idempotency_key' => $idempotencyKey,
            'payload_hash' => $payloadHash,
            'transaction_hash' => BrokerRequestInput::hash(
                $transactionSnapshot,
            ),
            'transaction_snapshot' => $transactionSnapshot,
            'occurred_at' => $occurredAt,
        ]);
        $transaction->forceFill([
            'current_event_id' => $transactionEvent->getKey(),
            'event_sequence' => 1,
        ])->save();

        $commission = BrokerCommission::query()->create([
            'organization_id' => $request->organization_id,
            'broker_request_id' => $request->getKey(),
            'broker_request_offer_id' => $offer->getKey(),
            'broker_transaction_id' => $transaction->getKey(),
            'status' => BrokerCommissionStatus::Pending,
            'rule_version' => $offer->commission_rule_version,
            'rate_basis_points' => $offer->commission_rate_basis_points,
            'base_minor' => $offer->commission_base_minor,
            'amount_minor' => $offer->commission_amount_minor,
            'currency_code' => $offer->currency_code,
            'source_transaction_event_id' => $transactionEvent->getKey(),
            'event_sequence' => 0,
            'recorded_at' => $occurredAt,
        ]);
        $commissionSnapshot = BrokerCommissionSnapshot::fromModel($commission);
        $commissionEvent = $commission->events()->create([
            'organization_id' => $request->organization_id,
            'broker_request_id' => $request->getKey(),
            'broker_request_offer_id' => $offer->getKey(),
            'broker_transaction_id' => $transaction->getKey(),
            'previous_event_id' => null,
            'actor_user_id' => $actor->getKey(),
            'sequence' => 1,
            'event_type' => BrokerCommissionEventType::Recorded,
            'from_status' => null,
            'to_status' => BrokerCommissionStatus::Pending,
            'reason_code' => 'offer_commission_recorded',
            'evidence_reference' => null,
            'idempotency_key' => $idempotencyKey,
            'payload_hash' => $payloadHash,
            'commission_hash' => BrokerRequestInput::hash(
                $commissionSnapshot,
            ),
            'commission_snapshot' => $commissionSnapshot,
            'occurred_at' => $occurredAt,
        ]);
        $commission->forceFill([
            'current_event_id' => $commissionEvent->getKey(),
            'event_sequence' => 1,
        ])->save();

        return [$transaction, $commission];
    }
}
