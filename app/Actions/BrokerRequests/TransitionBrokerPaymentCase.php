<?php

namespace App\Actions\BrokerRequests;

use App\BrokerRequests\BrokerPaymentCaseSnapshot;
use App\BrokerRequests\BrokerRequestInput;
use App\BrokerRequests\Data\BrokerPaymentCaseWriteResult;
use App\Enums\BrokerRequests\BrokerPaymentCaseEventType;
use App\Enums\BrokerRequests\BrokerPaymentCaseOutcome;
use App\Enums\BrokerRequests\BrokerPaymentCaseStatus;
use App\Enums\Validation\ApplicationValidationCode;
use App\Models\BrokerPaymentCase;
use App\Models\BrokerPaymentCaseEvent;
use App\Models\User;
use App\Support\Validation\ApplicationValidation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class TransitionBrokerPaymentCase
{
    private const MAX_EVENT_HISTORY = 1000;

    public function execute(
        BrokerPaymentCase $paymentCase,
        User $actor,
        BrokerPaymentCaseStatus $target,
        string $expectedCurrentEventId,
        string $idempotencyKey,
        string $reasonCode,
        string $evidenceReference,
        ?BrokerPaymentCaseOutcome $outcome = null,
        ?int $resolvedAmountMinor = null,
    ): BrokerPaymentCaseWriteResult {
        $this->assertEnabled();
        $this->assertOperator($actor);
        $reasonCode = trim($reasonCode);
        $evidenceReference = trim($evidenceReference);

        if ($evidenceReference === '') {
            ApplicationValidation::fail(
                'evidence_reference',
                ApplicationValidationCode::BrokerPaymentCaseOperatorEvidenceRequired,
            );
        }

        if (
            mb_strlen($evidenceReference) > 255
            || preg_match('/^[a-z0-9][a-z0-9._-]{2,63}$/', $reasonCode) !== 1
            || ! Str::isUlid($expectedCurrentEventId)
            || ! Str::isUuid($idempotencyKey)
        ) {
            throw new InvalidArgumentException(
                'The payment-case transition evidence or identifiers are invalid.',
            );
        }

        $eventType = $this->eventType($target);
        $payloadHash = BrokerRequestInput::hash([
            'operation' => $eventType->value,
            'broker_payment_case_id' => $paymentCase->getKey(),
            'expected_current_event_id' => $expectedCurrentEventId,
            'target_status' => $target->value,
            'resolution_outcome' => $outcome?->value,
            'resolved_amount_minor' => $resolvedAmountMinor,
            'reason_code' => $reasonCode,
            'evidence_reference' => $evidenceReference,
        ]);

        return DB::transaction(function () use (
            $paymentCase,
            $actor,
            $target,
            $eventType,
            $expectedCurrentEventId,
            $idempotencyKey,
            $reasonCode,
            $evidenceReference,
            $outcome,
            $resolvedAmountMinor,
            $payloadHash,
        ): BrokerPaymentCaseWriteResult {
            $locked = BrokerPaymentCase::query()
                ->forOrganization($paymentCase->organization_id)
                ->lockForUpdate()
                ->findOrFail($paymentCase->getKey());
            $existing = BrokerPaymentCaseEvent::query()
                ->where('broker_transaction_id', $locked->broker_transaction_id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                $this->assertReplay(
                    $existing,
                    $locked,
                    $eventType,
                    $payloadHash,
                );

                return new BrokerPaymentCaseWriteResult(
                    paymentCase: $locked,
                    event: $existing,
                    created: false,
                );
            }

            if ($locked->current_event_id !== $expectedCurrentEventId) {
                ApplicationValidation::fail(
                    'expected_current_event_id',
                    ApplicationValidationCode::BrokerPaymentCaseStale,
                );
            }

            if (! $this->transitionAllowed($locked->status, $target)) {
                ApplicationValidation::fail(
                    'target_status',
                    ApplicationValidationCode::BrokerPaymentCaseTransitionNotAllowed,
                );
            }

            $this->assertResolution(
                $locked,
                $target,
                $outcome,
                $resolvedAmountMinor,
            );

            if ($locked->event_sequence >= self::MAX_EVENT_HISTORY) {
                ApplicationValidation::fail(
                    'broker_payment_case',
                    ApplicationValidationCode::BrokerPaymentCaseHistoryLimit,
                );
            }

            $from = $locked->status;
            $occurredAt = now();
            $locked->forceFill([
                'status' => $target,
                'resolution_outcome' => $target === BrokerPaymentCaseStatus::Resolved
                    ? $outcome
                    : null,
                'resolved_amount_minor' => $target === BrokerPaymentCaseStatus::Resolved
                    ? $resolvedAmountMinor
                    : null,
                'resolved_at' => $target === BrokerPaymentCaseStatus::Resolved
                    ? $occurredAt
                    : null,
                'cancelled_at' => $target === BrokerPaymentCaseStatus::Cancelled
                    ? $occurredAt
                    : null,
            ]);
            $snapshot = BrokerPaymentCaseSnapshot::fromModel($locked);
            $event = $locked->events()->create([
                'organization_id' => $locked->organization_id,
                'broker_request_id' => $locked->broker_request_id,
                'broker_request_offer_id' => $locked->broker_request_offer_id,
                'broker_transaction_id' => $locked->broker_transaction_id,
                'previous_event_id' => $locked->current_event_id,
                'actor_user_id' => $actor->getKey(),
                'sequence' => $locked->event_sequence + 1,
                'event_type' => $eventType,
                'from_status' => $from,
                'to_status' => $target,
                'resolution_outcome' => $outcome,
                'reason_code' => $reasonCode,
                'evidence_reference' => $evidenceReference,
                'idempotency_key' => $idempotencyKey,
                'payload_hash' => $payloadHash,
                'payment_case_hash' => BrokerRequestInput::hash($snapshot),
                'payment_case_snapshot' => $snapshot,
                'occurred_at' => $occurredAt,
            ]);
            $locked->forceFill([
                'current_event_id' => $event->getKey(),
                'event_sequence' => $locked->event_sequence + 1,
            ])->save();

            return new BrokerPaymentCaseWriteResult(
                paymentCase: $locked,
                event: $event,
                created: true,
            );
        }, attempts: 3);
    }

    private function transitionAllowed(
        BrokerPaymentCaseStatus $from,
        BrokerPaymentCaseStatus $target,
    ): bool {
        return match ($from) {
            BrokerPaymentCaseStatus::Open => in_array(
                $target,
                [
                    BrokerPaymentCaseStatus::UnderReview,
                    BrokerPaymentCaseStatus::Cancelled,
                ],
                true,
            ),
            BrokerPaymentCaseStatus::UnderReview => in_array(
                $target,
                [
                    BrokerPaymentCaseStatus::Resolved,
                    BrokerPaymentCaseStatus::Cancelled,
                ],
                true,
            ),
            default => false,
        };
    }

    private function assertResolution(
        BrokerPaymentCase $case,
        BrokerPaymentCaseStatus $target,
        ?BrokerPaymentCaseOutcome $outcome,
        ?int $resolvedAmountMinor,
    ): void {
        if ($target !== BrokerPaymentCaseStatus::Resolved) {
            if ($outcome !== null || $resolvedAmountMinor !== null) {
                ApplicationValidation::fail(
                    'resolution_outcome',
                    ApplicationValidationCode::BrokerPaymentCaseResolutionInvalid,
                );
            }

            return;
        }

        if (
            $outcome === null
            || ! $outcome->supports($case->type)
            || $resolvedAmountMinor === null
            || $resolvedAmountMinor < 0
            || $resolvedAmountMinor > $case->requested_amount_minor
            || ($outcome->requiresPositiveAmount() && $resolvedAmountMinor < 1)
            || (! $outcome->requiresPositiveAmount() && $resolvedAmountMinor !== 0)
        ) {
            ApplicationValidation::fail(
                'resolution_outcome',
                ApplicationValidationCode::BrokerPaymentCaseResolutionInvalid,
            );
        }
    }

    private function eventType(
        BrokerPaymentCaseStatus $target,
    ): BrokerPaymentCaseEventType {
        return match ($target) {
            BrokerPaymentCaseStatus::UnderReview => (
                BrokerPaymentCaseEventType::ReviewStarted
            ),
            BrokerPaymentCaseStatus::Resolved => (
                BrokerPaymentCaseEventType::Resolved
            ),
            BrokerPaymentCaseStatus::Cancelled => (
                BrokerPaymentCaseEventType::Cancelled
            ),
            default => throw new InvalidArgumentException(
                'The target payment-case status is not operator writable.',
            ),
        };
    }

    private function assertEnabled(): void
    {
        if (! config('broker.payment_cases_enabled', false)) {
            ApplicationValidation::fail(
                'broker_payment_case',
                ApplicationValidationCode::BrokerPaymentCasesDisabled,
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
        BrokerPaymentCaseEvent $existing,
        BrokerPaymentCase $case,
        BrokerPaymentCaseEventType $eventType,
        string $payloadHash,
    ): void {
        if (
            $existing->broker_payment_case_id !== $case->getKey()
            || $existing->event_type !== $eventType
            || ! hash_equals($existing->payload_hash, $payloadHash)
        ) {
            ApplicationValidation::fail(
                'idempotency_key',
                ApplicationValidationCode::BrokerPaymentCaseIdempotencyConflict,
            );
        }
    }
}
