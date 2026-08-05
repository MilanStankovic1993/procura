<?php

namespace App\Actions\BrokerRequests;

use App\BrokerRequests\BrokerPaymentCaseSnapshot;
use App\BrokerRequests\BrokerRequestInput;
use App\BrokerRequests\Data\BrokerPaymentCaseWriteResult;
use App\Enums\BrokerRequests\BrokerPaymentCaseEventType;
use App\Enums\BrokerRequests\BrokerPaymentCaseStatus;
use App\Enums\BrokerRequests\BrokerPaymentCaseType;
use App\Enums\BrokerRequests\BrokerTransactionStatus;
use App\Enums\Validation\ApplicationValidationCode;
use App\Models\BrokerPaymentCase;
use App\Models\BrokerPaymentCaseEvent;
use App\Models\BrokerTransaction;
use App\Models\User;
use App\Support\Validation\ApplicationValidation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class OpenBrokerPaymentCase
{
    private const MAX_CASES_PER_TRANSACTION = 100;

    private const MAX_MONEY_MINOR = 9_007_199_254_740_991;

    public function execute(
        BrokerTransaction $transaction,
        User $actor,
        BrokerPaymentCaseType $type,
        int $requestedAmountMinor,
        string $expectedTransactionEventId,
        string $idempotencyKey,
        string $externalCaseReference,
        string $reasonCode,
        string $evidenceReference,
    ): BrokerPaymentCaseWriteResult {
        $this->assertEnabled();
        $this->assertOperator($actor);
        $externalCaseReference = trim($externalCaseReference);
        $reasonCode = trim($reasonCode);
        $evidenceReference = trim($evidenceReference);

        if ($evidenceReference === '') {
            ApplicationValidation::fail(
                'evidence_reference',
                ApplicationValidationCode::BrokerPaymentCaseOperatorEvidenceRequired,
            );
        }

        if (
            $externalCaseReference === ''
            || mb_strlen($externalCaseReference) > 255
            || mb_strlen($evidenceReference) > 255
            || preg_match('/^[a-z0-9][a-z0-9._-]{2,63}$/', $reasonCode) !== 1
            || ! Str::isUlid($expectedTransactionEventId)
            || ! Str::isUuid($idempotencyKey)
        ) {
            throw new InvalidArgumentException(
                'The payment-case evidence or identifiers are invalid.',
            );
        }

        if (
            $requestedAmountMinor < 1
            || $requestedAmountMinor > self::MAX_MONEY_MINOR
        ) {
            ApplicationValidation::fail(
                'requested_amount_minor',
                ApplicationValidationCode::BrokerPaymentCaseAmountInvalid,
            );
        }

        $logicalCaseHash = hash(
            'sha256',
            mb_strtolower($externalCaseReference, 'UTF-8'),
        );
        $payloadHash = BrokerRequestInput::hash([
            'operation' => BrokerPaymentCaseEventType::Opened->value,
            'broker_transaction_id' => $transaction->getKey(),
            'expected_transaction_event_id' => $expectedTransactionEventId,
            'type' => $type->value,
            'requested_amount_minor' => $requestedAmountMinor,
            'external_case_reference' => $externalCaseReference,
            'reason_code' => $reasonCode,
            'evidence_reference' => $evidenceReference,
        ]);

        return DB::transaction(function () use (
            $transaction,
            $actor,
            $type,
            $requestedAmountMinor,
            $expectedTransactionEventId,
            $idempotencyKey,
            $externalCaseReference,
            $logicalCaseHash,
            $reasonCode,
            $evidenceReference,
            $payloadHash,
        ): BrokerPaymentCaseWriteResult {
            $lockedTransaction = BrokerTransaction::query()
                ->forOrganization($transaction->organization_id)
                ->lockForUpdate()
                ->findOrFail($transaction->getKey());
            $existing = BrokerPaymentCaseEvent::query()
                ->where(
                    'broker_transaction_id',
                    $lockedTransaction->getKey(),
                )
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                $this->assertReplay($existing, $payloadHash);

                return new BrokerPaymentCaseWriteResult(
                    paymentCase: $existing->brokerPaymentCase()->firstOrFail(),
                    event: $existing,
                    created: false,
                );
            }

            if (
                $lockedTransaction->current_event_id
                !== $expectedTransactionEventId
            ) {
                ApplicationValidation::fail(
                    'expected_transaction_event_id',
                    ApplicationValidationCode::BrokerPaymentCaseStale,
                );
            }

            if (! $this->transactionAllowsCase($lockedTransaction->status)) {
                ApplicationValidation::fail(
                    'broker_transaction',
                    ApplicationValidationCode::BrokerPaymentCaseTransactionStateInvalid,
                );
            }

            if ($requestedAmountMinor > $lockedTransaction->payable_total_minor) {
                ApplicationValidation::fail(
                    'requested_amount_minor',
                    ApplicationValidationCode::BrokerPaymentCaseAmountInvalid,
                );
            }

            if (
                $lockedTransaction->paymentCases()->count()
                >= self::MAX_CASES_PER_TRANSACTION
            ) {
                ApplicationValidation::fail(
                    'broker_transaction',
                    ApplicationValidationCode::BrokerPaymentCaseCountLimit,
                );
            }

            if (
                $lockedTransaction->paymentCases()
                    ->where('type', $type->value)
                    ->where('logical_case_hash', $logicalCaseHash)
                    ->exists()
            ) {
                ApplicationValidation::fail(
                    'external_case_reference',
                    ApplicationValidationCode::BrokerPaymentCaseLogicalConflict,
                );
            }

            $occurredAt = now();
            $case = BrokerPaymentCase::query()->create([
                'organization_id' => $lockedTransaction->organization_id,
                'broker_request_id' => $lockedTransaction->broker_request_id,
                'broker_request_offer_id' => (
                    $lockedTransaction->broker_request_offer_id
                ),
                'broker_transaction_id' => $lockedTransaction->getKey(),
                'source_transaction_event_id' => $expectedTransactionEventId,
                'opened_by_user_id' => $actor->getKey(),
                'type' => $type,
                'status' => BrokerPaymentCaseStatus::Open,
                'requested_amount_minor' => $requestedAmountMinor,
                'resolved_amount_minor' => null,
                'currency_code' => $lockedTransaction->currency_code,
                'resolution_outcome' => null,
                'external_case_reference' => $externalCaseReference,
                'logical_case_hash' => $logicalCaseHash,
                'event_sequence' => 0,
                'opened_at' => $occurredAt,
            ]);
            $snapshot = BrokerPaymentCaseSnapshot::fromModel($case);
            $event = $case->events()->create([
                'organization_id' => $case->organization_id,
                'broker_request_id' => $case->broker_request_id,
                'broker_request_offer_id' => $case->broker_request_offer_id,
                'broker_transaction_id' => $case->broker_transaction_id,
                'previous_event_id' => null,
                'actor_user_id' => $actor->getKey(),
                'sequence' => 1,
                'event_type' => BrokerPaymentCaseEventType::Opened,
                'from_status' => null,
                'to_status' => BrokerPaymentCaseStatus::Open,
                'resolution_outcome' => null,
                'reason_code' => $reasonCode,
                'evidence_reference' => $evidenceReference,
                'idempotency_key' => $idempotencyKey,
                'payload_hash' => $payloadHash,
                'payment_case_hash' => BrokerRequestInput::hash($snapshot),
                'payment_case_snapshot' => $snapshot,
                'occurred_at' => $occurredAt,
            ]);
            $case->forceFill([
                'current_event_id' => $event->getKey(),
                'event_sequence' => 1,
            ])->save();

            return new BrokerPaymentCaseWriteResult(
                paymentCase: $case,
                event: $event,
                created: true,
            );
        }, attempts: 3);
    }

    private function transactionAllowsCase(BrokerTransactionStatus $status): bool
    {
        return in_array($status, [
            BrokerTransactionStatus::PaymentConfirmed,
            BrokerTransactionStatus::SupplierOrdered,
            BrokerTransactionStatus::Shipped,
            BrokerTransactionStatus::Delivered,
            BrokerTransactionStatus::Completed,
        ], true);
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
        string $payloadHash,
    ): void {
        if (
            $existing->event_type !== BrokerPaymentCaseEventType::Opened
            || ! hash_equals($existing->payload_hash, $payloadHash)
        ) {
            ApplicationValidation::fail(
                'idempotency_key',
                ApplicationValidationCode::BrokerPaymentCaseIdempotencyConflict,
            );
        }
    }
}
