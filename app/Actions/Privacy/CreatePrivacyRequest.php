<?php

namespace App\Actions\Privacy;

use App\Actions\Administration\RecordPlatformAuditEvent;
use App\Enums\Api\ApiErrorCode;
use App\Enums\Privacy\PrivacyRequestActorType;
use App\Enums\Privacy\PrivacyRequestStatus;
use App\Enums\Privacy\PrivacyRequestType;
use App\Enums\Validation\ApplicationValidationCode;
use App\Exceptions\PrivacyRequestConflictException;
use App\Models\PrivacyRequest;
use App\Models\User;
use App\Privacy\AccountDeletionBlockerResolver;
use App\Privacy\PrivacyWorkflowConfiguration;
use App\Support\Validation\ApplicationValidation;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use JsonException;

final class CreatePrivacyRequest
{
    public function __construct(
        private readonly PrivacyRequestEventRecorder $events,
        private readonly RecordPlatformAuditEvent $audit,
        private readonly PrivacyWorkflowConfiguration $configuration,
        private readonly AccountDeletionBlockerResolver $blockers,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{privacy_request: PrivacyRequest, created: bool}
     *
     * @throws JsonException
     */
    public function create(
        User $subject,
        array $attributes,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        $type = PrivacyRequestType::from($attributes['type']);
        $reason = $this->nullableTrimmed($attributes['reason'] ?? null);
        $countryCode = $this->nullableTrimmed(
            $attributes['residence_country_code'] ?? null,
        );
        $workflowVersion = $this->configuration->workflowVersion();
        $privacyNoticeVersion = (
            $this->configuration->privacyNoticeVersion()
        );
        $responseTargetDays = $this->configuration->responseTargetDays();
        $payloadHash = $this->payloadHash(
            $subject,
            $type,
            $countryCode,
            $reason,
            $workflowVersion,
            $privacyNoticeVersion,
        );

        return DB::transaction(function () use (
            $subject,
            $attributes,
            $type,
            $reason,
            $countryCode,
            $workflowVersion,
            $privacyNoticeVersion,
            $responseTargetDays,
            $payloadHash,
            $ipAddress,
            $userAgent,
        ): array {
            $lockedSubject = User::query()
                ->lockForUpdate()
                ->findOrFail($subject->getKey());
            $existing = PrivacyRequest::query()
                ->where('subject_user_id', $lockedSubject->getKey())
                ->where('idempotency_key', $attributes['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->payload_hash !== $payloadHash) {
                    throw new PrivacyRequestConflictException(
                        ApiErrorCode::PrivacyRequestIdempotencyConflict,
                        'idempotency_key',
                        'The idempotency key was already used with different privacy-request input.',
                    );
                }

                return [
                    'privacy_request' => $existing,
                    'created' => false,
                ];
            }

            $activeKey = hash(
                'sha256',
                $lockedSubject->getKey().'|'.$type->value,
            );
            $active = PrivacyRequest::query()
                ->where('active_key', $activeKey)
                ->lockForUpdate()
                ->first();

            if ($active !== null) {
                ApplicationValidation::fail(
                    'type',
                    ApplicationValidationCode::ActivePrivacyRequestExists,
                );
            }

            $requestedAt = CarbonImmutable::now();
            $blockingReasonCodes = $this->blockers->snapshot(
                $lockedSubject,
                $type,
            );

            try {
                $privacyRequest = PrivacyRequest::query()->create([
                    'subject_user_id' => $lockedSubject->getKey(),
                    'requester_email_hash' => hash(
                        'sha256',
                        mb_strtolower(trim($lockedSubject->email)),
                    ),
                    'type' => $type,
                    'status' => PrivacyRequestStatus::Requested,
                    'active_key' => $activeKey,
                    'event_sequence' => 0,
                    'residence_country_code' => $countryCode,
                    'preferred_locale' => $lockedSubject->preferred_locale,
                    'reason' => $reason,
                    'blocking_reason_codes' => $blockingReasonCodes,
                    'workflow_version' => $workflowVersion,
                    'privacy_notice_version' => $privacyNoticeVersion,
                    'idempotency_key' => $attributes['idempotency_key'],
                    'payload_hash' => $payloadHash,
                    'requested_at' => $requestedAt,
                    'response_target_at' => $requestedAt->addDays(
                        $responseTargetDays,
                    ),
                ]);
            } catch (QueryException $exception) {
                $collision = PrivacyRequest::query()
                    ->where('subject_user_id', $lockedSubject->getKey())
                    ->where(
                        'idempotency_key',
                        $attributes['idempotency_key'],
                    )
                    ->first();

                if ($collision === null) {
                    throw $exception;
                }

                if ($collision->payload_hash !== $payloadHash) {
                    throw new PrivacyRequestConflictException(
                        ApiErrorCode::PrivacyRequestIdempotencyConflict,
                        'idempotency_key',
                        'The idempotency key was already used with different privacy-request input.',
                    );
                }

                return [
                    'privacy_request' => $collision,
                    'created' => false,
                ];
            }

            $eventResult = $this->events->append(
                request: $privacyRequest,
                actor: $lockedSubject,
                actorType: PrivacyRequestActorType::Subject,
                nextStatus: PrivacyRequestStatus::Requested,
                reasonCode: 'privacy_request_submitted',
                note: null,
                evidenceReference: null,
                idempotencyKey: $attributes['idempotency_key'],
                expectedCurrentEventId: null,
            );

            $this->audit->record(
                actor: $lockedSubject,
                action: 'privacy_request.created',
                subject: $privacyRequest,
                reason: 'Self-service privacy request submitted.',
                newValues: [
                    'type' => $type->value,
                    'status' => PrivacyRequestStatus::Requested->value,
                    'blocking_reason_codes' => $blockingReasonCodes,
                    'current_event_id' => $eventResult['event']->getKey(),
                ],
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return [
                'privacy_request' => $privacyRequest->fresh(),
                'created' => true,
            ];
        }, attempts: 3);
    }

    /**
     * @throws JsonException
     */
    private function payloadHash(
        User $subject,
        PrivacyRequestType $type,
        ?string $countryCode,
        ?string $reason,
        string $workflowVersion,
        string $privacyNoticeVersion,
    ): string {
        return hash('sha256', json_encode([
            'subject_user_id' => $subject->getKey(),
            'type' => $type->value,
            'residence_country_code' => $countryCode,
            'reason' => $reason,
            'workflow_version' => $workflowVersion,
            'privacy_notice_version' => $privacyNoticeVersion,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function nullableTrimmed(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
