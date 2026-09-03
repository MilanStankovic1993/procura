<?php

namespace App\Actions\Privacy;

use App\Enums\Api\ApiErrorCode;
use App\Enums\Privacy\PrivacyRequestActorType;
use App\Enums\Privacy\PrivacyRequestStatus;
use App\Enums\Validation\ApplicationValidationCode;
use App\Exceptions\PrivacyRequestConflictException;
use App\Models\PrivacyRequest;
use App\Models\PrivacyRequestEvent;
use App\Models\User;
use App\Support\Validation\ApplicationValidation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use JsonException;
use LogicException;

final class PrivacyRequestEventRecorder
{
    /**
     * @return array{event: PrivacyRequestEvent, created: bool}
     *
     * @throws JsonException
     */
    public function append(
        PrivacyRequest $request,
        ?User $actor,
        PrivacyRequestActorType $actorType,
        PrivacyRequestStatus $nextStatus,
        string $reasonCode,
        ?string $note,
        ?string $evidenceReference,
        string $idempotencyKey,
        ?string $expectedCurrentEventId,
    ): array {
        if (DB::transactionLevel() < 1) {
            throw new LogicException(
                'Privacy request events must be recorded in a transaction.',
            );
        }

        $payloadHash = $this->payloadHash(
            $request,
            $actor,
            $actorType,
            $nextStatus,
            $reasonCode,
            $note,
            $evidenceReference,
            $expectedCurrentEventId,
        );
        $existing = PrivacyRequestEvent::query()
            ->where('privacy_request_id', $request->getKey())
            ->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            $this->assertReplay($existing, $payloadHash);

            return ['event' => $existing, 'created' => false];
        }

        $current = PrivacyRequestEvent::query()
            ->where('privacy_request_id', $request->getKey())
            ->orderByDesc('sequence')
            ->lockForUpdate()
            ->first();
        $currentId = $current?->getKey();

        if ($expectedCurrentEventId !== $currentId) {
            throw new PrivacyRequestConflictException(
                ApiErrorCode::PrivacyRequestStaleState,
                'expected_current_event_id',
                'The privacy request changed. Refresh it before recording another transition.',
            );
        }

        $priorStatus = $current?->next_status;

        if (
            ($priorStatus === null && $nextStatus !== PrivacyRequestStatus::Requested)
            || (
                $priorStatus !== null
                && ! $priorStatus->canTransitionTo($nextStatus)
            )
        ) {
            ApplicationValidation::fail(
                'next_status',
                ApplicationValidationCode::PrivacyTransitionNotAllowed,
            );
        }

        $sequence = ($current?->sequence ?? 0) + 1;

        if (
            $sequence > (int) config(
                'privacy.maximum_events_per_request',
            )
        ) {
            ApplicationValidation::fail(
                'privacy_request',
                ApplicationValidationCode::PrivacyEventHistoryLimit,
            );
        }

        try {
            $event = PrivacyRequestEvent::query()->create([
                'privacy_request_id' => $request->getKey(),
                'previous_event_id' => $currentId,
                'actor_user_id' => $actor?->getKey(),
                'sequence' => $sequence,
                'prior_status' => $priorStatus,
                'next_status' => $nextStatus,
                'actor_type' => $actorType,
                'reason_code' => $reasonCode,
                'note' => $note,
                'evidence_reference' => $evidenceReference,
                'idempotency_key' => $idempotencyKey,
                'payload_hash' => $payloadHash,
                'occurred_at' => now(),
                'created_at' => now(),
            ]);
        } catch (QueryException $exception) {
            $collision = PrivacyRequestEvent::query()
                ->where('privacy_request_id', $request->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($collision === null) {
                throw $exception;
            }

            $this->assertReplay($collision, $payloadHash);

            return ['event' => $collision, 'created' => false];
        }

        $request->forceFill([
            'status' => $nextStatus,
            'current_event_id' => $event->getKey(),
            'event_sequence' => $sequence,
            'active_key' => $nextStatus->isTerminal()
                ? null
                : $request->active_key,
            'resolved_at' => $nextStatus->isTerminal() ? now() : null,
        ])->save();

        return ['event' => $event, 'created' => true];
    }

    private function assertReplay(
        PrivacyRequestEvent $event,
        string $payloadHash,
    ): void {
        if ($event->payload_hash !== $payloadHash) {
            throw new PrivacyRequestConflictException(
                ApiErrorCode::PrivacyRequestIdempotencyConflict,
                'idempotency_key',
                'The idempotency key was already used with a different privacy-request command.',
            );
        }
    }

    /**
     * @throws JsonException
     */
    private function payloadHash(
        PrivacyRequest $request,
        ?User $actor,
        PrivacyRequestActorType $actorType,
        PrivacyRequestStatus $nextStatus,
        string $reasonCode,
        ?string $note,
        ?string $evidenceReference,
        ?string $expectedCurrentEventId,
    ): string {
        return hash('sha256', json_encode([
            'privacy_request_id' => $request->getKey(),
            'actor_user_id' => $actor?->getKey(),
            'actor_type' => $actorType->value,
            'expected_current_event_id' => $expectedCurrentEventId,
            'next_status' => $nextStatus->value,
            'reason_code' => $reasonCode,
            'note' => $note,
            'evidence_reference' => $evidenceReference,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
