<?php

namespace App\Actions\BrokerRequests;

use App\BrokerRequests\BrokerCommissionSnapshot;
use App\BrokerRequests\BrokerRequestInput;
use App\BrokerRequests\Data\BrokerCommissionWriteResult;
use App\Enums\BrokerRequests\BrokerCommissionEventType;
use App\Enums\BrokerRequests\BrokerCommissionStatus;
use App\Enums\Validation\ApplicationValidationCode;
use App\Models\BrokerCommission;
use App\Models\BrokerCommissionEvent;
use App\Models\User;
use App\Support\Validation\ApplicationValidation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class SettleBrokerCommission
{
    private const MAX_EVENT_HISTORY = 1000;

    public function execute(
        BrokerCommission $commission,
        User $actor,
        string $expectedCurrentEventId,
        string $idempotencyKey,
        string $reasonCode,
        string $evidenceReference,
    ): BrokerCommissionWriteResult {
        $this->assertEnabled();
        $this->assertOperator($actor);
        $reasonCode = trim($reasonCode);
        $evidenceReference = trim($evidenceReference);

        if ($evidenceReference === '') {
            ApplicationValidation::fail(
                'evidence_reference',
                ApplicationValidationCode::BrokerCommissionOperatorEvidenceRequired,
            );
        }

        if (
            preg_match('/^[a-z0-9][a-z0-9._-]{2,63}$/', $reasonCode) !== 1
            || mb_strlen($evidenceReference) > 255
            || ! Str::isUlid($expectedCurrentEventId)
            || ! Str::isUuid($idempotencyKey)
        ) {
            throw new InvalidArgumentException(
                'The commission settlement evidence or identifiers are invalid.',
            );
        }

        $payloadHash = BrokerRequestInput::hash([
            'operation' => BrokerCommissionEventType::Settled->value,
            'broker_commission_id' => $commission->getKey(),
            'expected_current_event_id' => $expectedCurrentEventId,
            'reason_code' => $reasonCode,
            'evidence_reference' => $evidenceReference,
        ]);

        return DB::transaction(function () use (
            $commission,
            $actor,
            $expectedCurrentEventId,
            $idempotencyKey,
            $reasonCode,
            $evidenceReference,
            $payloadHash,
        ): BrokerCommissionWriteResult {
            $locked = BrokerCommission::query()
                ->forOrganization($commission->organization_id)
                ->lockForUpdate()
                ->findOrFail($commission->getKey());
            $existing = $locked->events()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                $this->assertReplay($existing, $payloadHash);

                return new BrokerCommissionWriteResult(
                    commission: $locked,
                    event: $existing,
                    created: false,
                );
            }

            if ($locked->current_event_id !== $expectedCurrentEventId) {
                ApplicationValidation::fail(
                    'expected_current_event_id',
                    ApplicationValidationCode::BrokerCommissionStale,
                );
            }

            if ($locked->status !== BrokerCommissionStatus::Earned) {
                ApplicationValidation::fail(
                    'broker_commission',
                    ApplicationValidationCode::BrokerCommissionTransitionNotAllowed,
                );
            }

            if ($locked->event_sequence >= self::MAX_EVENT_HISTORY) {
                ApplicationValidation::fail(
                    'broker_commission',
                    ApplicationValidationCode::BrokerCommissionHistoryLimit,
                );
            }

            $occurredAt = now();
            $locked->forceFill([
                'status' => BrokerCommissionStatus::Settled,
                'settled_at' => $occurredAt,
            ]);
            $snapshot = BrokerCommissionSnapshot::fromModel($locked);
            $event = $locked->events()->create([
                'organization_id' => $locked->organization_id,
                'broker_request_id' => $locked->broker_request_id,
                'broker_request_offer_id' => (
                    $locked->broker_request_offer_id
                ),
                'broker_transaction_id' => $locked->broker_transaction_id,
                'previous_event_id' => $locked->current_event_id,
                'actor_user_id' => $actor->getKey(),
                'sequence' => $locked->event_sequence + 1,
                'event_type' => BrokerCommissionEventType::Settled,
                'from_status' => BrokerCommissionStatus::Earned,
                'to_status' => BrokerCommissionStatus::Settled,
                'reason_code' => $reasonCode,
                'evidence_reference' => $evidenceReference,
                'idempotency_key' => $idempotencyKey,
                'payload_hash' => $payloadHash,
                'commission_hash' => BrokerRequestInput::hash($snapshot),
                'commission_snapshot' => $snapshot,
                'occurred_at' => $occurredAt,
            ]);
            $locked->forceFill([
                'current_event_id' => $event->getKey(),
                'event_sequence' => $locked->event_sequence + 1,
            ])->save();

            return new BrokerCommissionWriteResult(
                commission: $locked,
                event: $event,
                created: true,
            );
        }, attempts: 3);
    }

    private function assertEnabled(): void
    {
        if (! config('broker.transactions_enabled', false)) {
            ApplicationValidation::fail(
                'broker_commission',
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
        BrokerCommissionEvent $existing,
        string $payloadHash,
    ): void {
        if (
            $existing->event_type !== BrokerCommissionEventType::Settled
            || ! hash_equals($existing->payload_hash, $payloadHash)
        ) {
            ApplicationValidation::fail(
                'idempotency_key',
                ApplicationValidationCode::BrokerCommissionIdempotencyConflict,
            );
        }
    }
}
