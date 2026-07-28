<?php

namespace App\Actions\Privacy;

use App\Actions\Administration\RecordPlatformAuditEvent;
use App\Enums\Privacy\PrivacyRequestActorType;
use App\Enums\Privacy\PrivacyRequestStatus;
use App\Models\PrivacyRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use JsonException;

final class CancelPrivacyRequest
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
    public function cancel(
        PrivacyRequest $request,
        User $subject,
        string $expectedCurrentEventId,
        string $idempotencyKey,
        ?string $note = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        return DB::transaction(function () use (
            $request,
            $subject,
            $expectedCurrentEventId,
            $idempotencyKey,
            $note,
            $ipAddress,
            $userAgent,
        ): array {
            $lockedRequest = PrivacyRequest::query()
                ->lockForUpdate()
                ->findOrFail($request->getKey());
            $lockedSubject = User::query()
                ->lockForUpdate()
                ->findOrFail($subject->getKey());

            if (
                $lockedRequest->subject_user_id
                    !== $lockedSubject->getKey()
            ) {
                throw new AuthorizationException;
            }

            $result = $this->events->append(
                request: $lockedRequest,
                actor: $lockedSubject,
                actorType: PrivacyRequestActorType::Subject,
                nextStatus: PrivacyRequestStatus::Cancelled,
                reasonCode: 'cancelled_by_subject',
                note: $note === null ? null : trim($note),
                evidenceReference: null,
                idempotencyKey: $idempotencyKey,
                expectedCurrentEventId: $expectedCurrentEventId,
            );

            if ($result['created']) {
                $this->audit->record(
                    actor: $lockedSubject,
                    action: 'privacy_request.cancelled',
                    subject: $lockedRequest,
                    reason: 'Privacy request cancelled by its subject.',
                    oldValues: [
                        'status' => $result['event']->prior_status?->value,
                    ],
                    newValues: [
                        'status' => PrivacyRequestStatus::Cancelled->value,
                        'current_event_id' => $result['event']->getKey(),
                    ],
                    ipAddress: $ipAddress,
                    userAgent: $userAgent,
                );
            }

            return [
                'privacy_request' => $lockedRequest->fresh(),
                'event_created' => $result['created'],
            ];
        }, attempts: 3);
    }
}
