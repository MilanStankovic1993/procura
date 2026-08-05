<?php

namespace App\Actions\Privacy;

use App\Actions\Administration\RecordPlatformAuditEvent;
use App\Enums\Privacy\PrivacyRequestActorType;
use App\Enums\Privacy\PrivacyRequestStatus;
use App\Enums\Validation\ApplicationValidationCode;
use App\Models\PrivacyRequest;
use App\Models\User;
use App\Support\Validation\ApplicationValidation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;

final class TransitionPrivacyRequest
{
    public function __construct(
        private readonly PrivacyRequestEventRecorder $events,
        private readonly RecordPlatformAuditEvent $audit,
    ) {}

    /**
     * @return array{privacy_request: PrivacyRequest, event_created: bool}
     *
     * @throws JsonException
     */
    public function transition(
        PrivacyRequest $request,
        User $operator,
        PrivacyRequestStatus $nextStatus,
        string $expectedCurrentEventId,
        string $idempotencyKey,
        string $reasonCode,
        string $note,
        ?string $evidenceReference = null,
    ): array {
        $reasonCode = trim($reasonCode);
        $note = trim($note);
        $evidenceReference = $evidenceReference === null
            ? null
            : trim($evidenceReference);

        $this->validateInput(
            $nextStatus,
            $reasonCode,
            $note,
            $evidenceReference,
        );

        return DB::transaction(function () use (
            $request,
            $operator,
            $nextStatus,
            $expectedCurrentEventId,
            $idempotencyKey,
            $reasonCode,
            $note,
            $evidenceReference,
        ): array {
            $lockedOperator = User::query()
                ->lockForUpdate()
                ->findOrFail($operator->getKey());

            if (
                ! $lockedOperator->is_super_admin
                || ! $lockedOperator->hasVerifiedEmail()
            ) {
                throw new AuthorizationException;
            }

            $lockedRequest = PrivacyRequest::query()
                ->lockForUpdate()
                ->findOrFail($request->getKey());
            $result = $this->events->append(
                request: $lockedRequest,
                actor: $lockedOperator,
                actorType: PrivacyRequestActorType::Operator,
                nextStatus: $nextStatus,
                reasonCode: $reasonCode,
                note: $note,
                evidenceReference: $evidenceReference,
                idempotencyKey: $idempotencyKey,
                expectedCurrentEventId: $expectedCurrentEventId,
            );

            if ($result['created']) {
                $this->audit->record(
                    actor: $lockedOperator,
                    action: 'privacy_request.transitioned',
                    subject: $lockedRequest,
                    reason: $note,
                    oldValues: [
                        'status' => $result['event']->prior_status?->value,
                    ],
                    newValues: [
                        'status' => $nextStatus->value,
                        'reason_code' => $reasonCode,
                        'evidence_reference' => $evidenceReference,
                        'current_event_id' => $result['event']->getKey(),
                    ],
                );
            }

            return [
                'privacy_request' => $lockedRequest->fresh(),
                'event_created' => $result['created'],
            ];
        }, attempts: 3);
    }

    private function validateInput(
        PrivacyRequestStatus $nextStatus,
        string $reasonCode,
        string $note,
        ?string $evidenceReference,
    ): void {
        if ($nextStatus === PrivacyRequestStatus::Fulfilled) {
            ApplicationValidation::fail(
                'next_status',
                ApplicationValidationCode::PrivacyFulfillmentReserved,
            );
        }

        if (! preg_match('/^[a-z0-9][a-z0-9_-]{2,79}$/', $reasonCode)) {
            throw new InvalidArgumentException(
                'The reason code must contain 3 to 80 lowercase identifier characters.',
            );
        }

        if (mb_strlen($note) < 10 || mb_strlen($note) > 1000) {
            throw new InvalidArgumentException(
                'The operational note must contain between 10 and 1000 characters.',
            );
        }

        if (
            in_array($nextStatus, [
                PrivacyRequestStatus::Approved,
                PrivacyRequestStatus::Fulfilled,
                PrivacyRequestStatus::Rejected,
            ], true)
            && (
                $evidenceReference === null
                || mb_strlen($evidenceReference) < 3
                || mb_strlen($evidenceReference) > 255
            )
        ) {
            throw new InvalidArgumentException(
                'Approved, fulfilled, and rejected transitions require an evidence reference.',
            );
        }
    }
}
