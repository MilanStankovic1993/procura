<?php

namespace App\Actions\BrokerRequests;

use App\BrokerRequests\BrokerCommissionSnapshot;
use App\BrokerRequests\BrokerRequestInput;
use App\BrokerRequests\BrokerRequestSnapshot;
use App\BrokerRequests\BrokerTransactionSnapshot;
use App\BrokerRequests\Data\BrokerTransactionWriteResult;
use App\Enums\BrokerRequests\BrokerCommissionEventType;
use App\Enums\BrokerRequests\BrokerCommissionStatus;
use App\Enums\BrokerRequests\BrokerRequestEventType;
use App\Enums\BrokerRequests\BrokerRequestStatus;
use App\Enums\BrokerRequests\BrokerTransactionEventType;
use App\Enums\BrokerRequests\BrokerTransactionStatus;
use App\Enums\Validation\ApplicationValidationCode;
use App\Models\BrokerCommission;
use App\Models\BrokerCommissionEvent;
use App\Models\BrokerRequest;
use App\Models\BrokerRequestEvent;
use App\Models\BrokerTransaction;
use App\Models\BrokerTransactionEvent;
use App\Models\User;
use App\Support\Validation\ApplicationValidation;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class TransitionBrokerTransaction
{
    private const MAX_EVENT_HISTORY = 1000;

    public function execute(
        BrokerTransaction $transaction,
        User $actor,
        BrokerTransactionStatus $target,
        string $expectedCurrentEventId,
        string $idempotencyKey,
        string $reasonCode,
        string $evidenceReference,
    ): BrokerTransactionWriteResult {
        $this->assertEnabled();
        $this->assertOperator($actor);
        $reasonCode = trim($reasonCode);
        $evidenceReference = trim($evidenceReference);

        if ($evidenceReference === '') {
            ApplicationValidation::fail(
                'evidence_reference',
                ApplicationValidationCode::BrokerTransactionOperatorEvidenceRequired,
            );
        }

        if (
            preg_match('/^[a-z0-9][a-z0-9._-]{2,63}$/', $reasonCode) !== 1
            || mb_strlen($evidenceReference) > 255
            || ! Str::isUlid($expectedCurrentEventId)
            || ! Str::isUuid($idempotencyKey)
        ) {
            throw new InvalidArgumentException(
                'The transaction transition evidence or identifiers are invalid.',
            );
        }

        $eventType = $this->eventType($target);
        $payloadHash = BrokerRequestInput::hash([
            'operation' => $eventType->value,
            'broker_transaction_id' => $transaction->getKey(),
            'expected_current_event_id' => $expectedCurrentEventId,
            'target_status' => $target->value,
            'reason_code' => $reasonCode,
            'evidence_reference' => $evidenceReference,
        ]);

        return DB::transaction(function () use (
            $transaction,
            $actor,
            $target,
            $eventType,
            $expectedCurrentEventId,
            $idempotencyKey,
            $reasonCode,
            $evidenceReference,
            $payloadHash,
        ): BrokerTransactionWriteResult {
            $locked = BrokerTransaction::query()
                ->forOrganization($transaction->organization_id)
                ->lockForUpdate()
                ->findOrFail($transaction->getKey());
            $existing = $locked->events()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                $this->assertReplay($existing, $eventType, $payloadHash);

                return new BrokerTransactionWriteResult(
                    transaction: $locked,
                    transactionEvent: $existing,
                    commission: $locked->commission()->firstOrFail(),
                    commissionEvent: BrokerCommissionEvent::query()
                        ->where('idempotency_key', $idempotencyKey)
                        ->where(
                            'broker_transaction_id',
                            $locked->getKey(),
                        )
                        ->first(),
                    brokerRequest: $locked->brokerRequest()->first(),
                    requestEvent: BrokerRequestEvent::query()
                        ->where('idempotency_key', $idempotencyKey)
                        ->where(
                            'broker_request_id',
                            $locked->broker_request_id,
                        )
                        ->first(),
                    created: false,
                );
            }

            if ($locked->current_event_id !== $expectedCurrentEventId) {
                ApplicationValidation::fail(
                    'expected_current_event_id',
                    ApplicationValidationCode::BrokerTransactionStale,
                );
            }

            if (! $this->transitionAllowed($locked->status, $target)) {
                ApplicationValidation::fail(
                    'target_status',
                    ApplicationValidationCode::BrokerTransactionTransitionNotAllowed,
                );
            }

            if ($locked->event_sequence >= self::MAX_EVENT_HISTORY) {
                ApplicationValidation::fail(
                    'broker_transaction',
                    ApplicationValidationCode::BrokerTransactionHistoryLimit,
                );
            }

            $occurredAt = now();
            $locked->forceFill([
                'status' => $target,
                ...$this->timestampChanges($target, $occurredAt),
            ]);
            $snapshot = BrokerTransactionSnapshot::fromModel($locked);
            $event = $locked->events()->create([
                'organization_id' => $locked->organization_id,
                'broker_request_id' => $locked->broker_request_id,
                'broker_request_offer_id' => (
                    $locked->broker_request_offer_id
                ),
                'previous_event_id' => $locked->current_event_id,
                'actor_user_id' => $actor->getKey(),
                'sequence' => $locked->event_sequence + 1,
                'event_type' => $eventType,
                'from_status' => $locked->getOriginal('status'),
                'to_status' => $target,
                'reason_code' => $reasonCode,
                'evidence_reference' => $evidenceReference,
                'idempotency_key' => $idempotencyKey,
                'payload_hash' => $payloadHash,
                'transaction_hash' => BrokerRequestInput::hash($snapshot),
                'transaction_snapshot' => $snapshot,
                'occurred_at' => $occurredAt,
            ]);
            $locked->forceFill([
                'current_event_id' => $event->getKey(),
                'event_sequence' => $locked->event_sequence + 1,
            ])->save();

            $request = null;
            $requestEvent = null;
            $commission = $locked->commission()
                ->lockForUpdate()
                ->firstOrFail();
            $commissionEvent = null;

            if ($target->isTerminal()) {
                [$request, $requestEvent] = $this->resolveRequest(
                    transaction: $locked,
                    actor: $actor,
                    target: $target,
                    idempotencyKey: $idempotencyKey,
                    payloadHash: $payloadHash,
                    reasonCode: $reasonCode,
                    evidenceReference: $evidenceReference,
                    occurredAt: $occurredAt,
                );
                $commissionEvent = $this->resolveCommission(
                    transaction: $locked,
                    commission: $commission,
                    actor: $actor,
                    target: $target,
                    idempotencyKey: $idempotencyKey,
                    payloadHash: $payloadHash,
                    reasonCode: $reasonCode,
                    evidenceReference: $evidenceReference,
                    occurredAt: $occurredAt,
                );
            }

            return new BrokerTransactionWriteResult(
                transaction: $locked,
                transactionEvent: $event,
                commission: $commission,
                commissionEvent: $commissionEvent,
                brokerRequest: $request,
                requestEvent: $requestEvent,
                created: true,
            );
        }, attempts: 3);
    }

    private function resolveRequest(
        BrokerTransaction $transaction,
        User $actor,
        BrokerTransactionStatus $target,
        string $idempotencyKey,
        string $payloadHash,
        string $reasonCode,
        string $evidenceReference,
        CarbonInterface $occurredAt,
    ): array {
        $request = BrokerRequest::query()
            ->forOrganization($transaction->organization_id)
            ->lockForUpdate()
            ->findOrFail($transaction->broker_request_id);

        if ($request->status !== BrokerRequestStatus::Accepted) {
            ApplicationValidation::fail(
                'broker_request',
                ApplicationValidationCode::BrokerTransactionRequestStateInvalid,
            );
        }

        if ($request->event_sequence >= self::MAX_EVENT_HISTORY) {
            ApplicationValidation::fail(
                'broker_request',
                ApplicationValidationCode::BrokerRequestHistoryLimit,
            );
        }

        $requestTarget = $target === BrokerTransactionStatus::Completed
            ? BrokerRequestStatus::Completed
            : BrokerRequestStatus::Cancelled;
        $snapshot = BrokerRequestSnapshot::fromModel($request, $requestTarget);
        $event = $request->events()->create([
            'organization_id' => $request->organization_id,
            'previous_event_id' => $request->current_event_id,
            'actor_user_id' => $actor->getKey(),
            'sequence' => $request->event_sequence + 1,
            'event_type' => $requestTarget === BrokerRequestStatus::Completed
                ? BrokerRequestEventType::TransactionCompleted
                : BrokerRequestEventType::Cancelled,
            'from_status' => $request->status,
            'to_status' => $requestTarget,
            'reason_code' => $reasonCode,
            'evidence_reference' => $evidenceReference,
            'idempotency_key' => $idempotencyKey,
            'payload_hash' => $payloadHash,
            'request_hash' => BrokerRequestInput::hash($snapshot),
            'request_snapshot' => $snapshot,
            'occurred_at' => $occurredAt,
        ]);
        $request->forceFill([
            'status' => $requestTarget,
            'current_event_id' => $event->getKey(),
            'event_sequence' => $request->event_sequence + 1,
            'resolved_at' => $occurredAt,
        ])->save();

        return [$request, $event];
    }

    private function resolveCommission(
        BrokerTransaction $transaction,
        BrokerCommission $commission,
        User $actor,
        BrokerTransactionStatus $target,
        string $idempotencyKey,
        string $payloadHash,
        string $reasonCode,
        string $evidenceReference,
        CarbonInterface $occurredAt,
    ): BrokerCommissionEvent {
        if ($commission->status !== BrokerCommissionStatus::Pending) {
            ApplicationValidation::fail(
                'broker_commission',
                ApplicationValidationCode::BrokerCommissionTransitionNotAllowed,
            );
        }

        if ($commission->event_sequence >= self::MAX_EVENT_HISTORY) {
            ApplicationValidation::fail(
                'broker_commission',
                ApplicationValidationCode::BrokerCommissionHistoryLimit,
            );
        }

        $commissionTarget = $target === BrokerTransactionStatus::Completed
            ? BrokerCommissionStatus::Earned
            : BrokerCommissionStatus::Waived;
        $commission->forceFill([
            'status' => $commissionTarget,
            'earned_at' => $commissionTarget === BrokerCommissionStatus::Earned
                ? $occurredAt
                : null,
            'waived_at' => $commissionTarget === BrokerCommissionStatus::Waived
                ? $occurredAt
                : null,
        ]);
        $snapshot = BrokerCommissionSnapshot::fromModel($commission);
        $event = $commission->events()->create([
            'organization_id' => $transaction->organization_id,
            'broker_request_id' => $transaction->broker_request_id,
            'broker_request_offer_id' => (
                $transaction->broker_request_offer_id
            ),
            'broker_transaction_id' => $transaction->getKey(),
            'previous_event_id' => $commission->current_event_id,
            'actor_user_id' => $actor->getKey(),
            'sequence' => $commission->event_sequence + 1,
            'event_type' => $commissionTarget
                === BrokerCommissionStatus::Earned
                ? BrokerCommissionEventType::Earned
                : BrokerCommissionEventType::Waived,
            'from_status' => BrokerCommissionStatus::Pending,
            'to_status' => $commissionTarget,
            'reason_code' => $reasonCode,
            'evidence_reference' => $evidenceReference,
            'idempotency_key' => $idempotencyKey,
            'payload_hash' => $payloadHash,
            'commission_hash' => BrokerRequestInput::hash($snapshot),
            'commission_snapshot' => $snapshot,
            'occurred_at' => $occurredAt,
        ]);
        $commission->forceFill([
            'current_event_id' => $event->getKey(),
            'event_sequence' => $commission->event_sequence + 1,
        ])->save();

        return $event;
    }

    private function transitionAllowed(
        BrokerTransactionStatus $from,
        BrokerTransactionStatus $target,
    ): bool {
        return match ($from) {
            BrokerTransactionStatus::AwaitingPayment => in_array(
                $target,
                [
                    BrokerTransactionStatus::PaymentConfirmed,
                    BrokerTransactionStatus::Cancelled,
                ],
                true,
            ),
            BrokerTransactionStatus::PaymentConfirmed => (
                $target === BrokerTransactionStatus::SupplierOrdered
            ),
            BrokerTransactionStatus::SupplierOrdered => (
                $target === BrokerTransactionStatus::Shipped
            ),
            BrokerTransactionStatus::Shipped => (
                $target === BrokerTransactionStatus::Delivered
            ),
            BrokerTransactionStatus::Delivered => (
                $target === BrokerTransactionStatus::Completed
            ),
            default => false,
        };
    }

    private function eventType(
        BrokerTransactionStatus $target,
    ): BrokerTransactionEventType {
        return match ($target) {
            BrokerTransactionStatus::PaymentConfirmed => (
                BrokerTransactionEventType::PaymentConfirmed
            ),
            BrokerTransactionStatus::SupplierOrdered => (
                BrokerTransactionEventType::SupplierOrdered
            ),
            BrokerTransactionStatus::Shipped => (
                BrokerTransactionEventType::Shipped
            ),
            BrokerTransactionStatus::Delivered => (
                BrokerTransactionEventType::Delivered
            ),
            BrokerTransactionStatus::Completed => (
                BrokerTransactionEventType::Completed
            ),
            BrokerTransactionStatus::Cancelled => (
                BrokerTransactionEventType::Cancelled
            ),
            default => throw new InvalidArgumentException(
                'The target transaction status is not operator writable.',
            ),
        };
    }

    private function timestampChanges(
        BrokerTransactionStatus $target,
        CarbonInterface $occurredAt,
    ): array {
        return match ($target) {
            BrokerTransactionStatus::PaymentConfirmed => [
                'payment_confirmed_at' => $occurredAt,
            ],
            BrokerTransactionStatus::SupplierOrdered => [
                'ordered_at' => $occurredAt,
            ],
            BrokerTransactionStatus::Shipped => [
                'shipped_at' => $occurredAt,
            ],
            BrokerTransactionStatus::Delivered => [
                'delivered_at' => $occurredAt,
            ],
            BrokerTransactionStatus::Completed => [
                'completed_at' => $occurredAt,
            ],
            BrokerTransactionStatus::Cancelled => [
                'cancelled_at' => $occurredAt,
            ],
            default => [],
        };
    }

    private function assertEnabled(): void
    {
        if (! config('broker.transactions_enabled', false)) {
            ApplicationValidation::fail(
                'broker_transaction',
                ApplicationValidationCode::BrokerTransactionsDisabled,
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
        BrokerTransactionEvent $existing,
        BrokerTransactionEventType $eventType,
        string $payloadHash,
    ): void {
        if (
            $existing->event_type !== $eventType
            || ! hash_equals($existing->payload_hash, $payloadHash)
        ) {
            ApplicationValidation::fail(
                'idempotency_key',
                ApplicationValidationCode::BrokerTransactionIdempotencyConflict,
            );
        }
    }
}
