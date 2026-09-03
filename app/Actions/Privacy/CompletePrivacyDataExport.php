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
use App\Models\PrivacyRequestFulfillment;
use App\Models\User;
use App\Privacy\PrivacyWorkflowConfiguration;
use App\Support\Validation\ApplicationValidation;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;

final class CompletePrivacyDataExport
{
    public function __construct(
        private readonly PrivacyRequestEventRecorder $events,
        private readonly RecordPlatformAuditEvent $audit,
        private readonly PrivacyWorkflowConfiguration $configuration,
    ) {}

    /**
     * @return array{
     *     privacy_request: PrivacyRequest,
     *     fulfillment: PrivacyRequestFulfillment,
     *     receipt_created: bool
     * }
     *
     * @throws JsonException
     */
    public function complete(
        PrivacyRequest $request,
        User $operator,
        string $expectedCurrentEventId,
        string $idempotencyKey,
        string $dataInventoryVersion,
        string $identityEvidenceReference,
        string $artifactReference,
        string $artifactSha256,
        int $artifactSizeBytes,
        CarbonImmutable $artifactExpiresAt,
        string $deliveryEvidenceReference,
        string $note,
    ): array {
        $expectedCurrentEventId = trim($expectedCurrentEventId);
        $idempotencyKey = trim($idempotencyKey);
        $dataInventoryVersion = trim($dataInventoryVersion);
        $identityEvidenceReference = trim($identityEvidenceReference);
        $artifactReference = trim($artifactReference);
        $artifactSha256 = Str::lower(trim($artifactSha256));
        $deliveryEvidenceReference = trim($deliveryEvidenceReference);
        $note = trim($note);

        $this->validateStructuralInput(
            $idempotencyKey,
            $identityEvidenceReference,
            $artifactReference,
            $artifactSha256,
            $deliveryEvidenceReference,
            $note,
        );

        return DB::transaction(function () use (
            $request,
            $operator,
            $expectedCurrentEventId,
            $idempotencyKey,
            $dataInventoryVersion,
            $identityEvidenceReference,
            $artifactReference,
            $artifactSha256,
            $artifactSizeBytes,
            $artifactExpiresAt,
            $deliveryEvidenceReference,
            $note,
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
            $existing = PrivacyRequestFulfillment::query()
                ->where('privacy_request_id', $lockedRequest->getKey())
                ->lockForUpdate()
                ->first();
            $executionVersion = $existing?->execution_version
                ?? $this->configuration->fulfillmentExecutionVersion();
            $payloadHash = $this->payloadHash(
                $lockedRequest,
                $lockedOperator,
                $expectedCurrentEventId,
                $idempotencyKey,
                $executionVersion,
                $dataInventoryVersion,
                $identityEvidenceReference,
                $artifactReference,
                $artifactSha256,
                $artifactSizeBytes,
                $artifactExpiresAt,
                $deliveryEvidenceReference,
                $note,
            );

            if ($existing !== null) {
                if ($existing->payload_hash !== $payloadHash) {
                    throw new PrivacyRequestConflictException(
                        ApiErrorCode::PrivacyRequestIdempotencyConflict,
                        'idempotency_key',
                        'This privacy request already has a different fulfillment receipt.',
                    );
                }

                return [
                    'privacy_request' => $lockedRequest,
                    'fulfillment' => $existing,
                    'receipt_created' => false,
                ];
            }

            $this->validateNewFulfillment(
                $dataInventoryVersion,
                $artifactSizeBytes,
                $artifactExpiresAt,
            );

            if (! $this->configuration->fulfillmentEnabled()) {
                ApplicationValidation::fail(
                    'privacy_request',
                    ApplicationValidationCode::PrivacyFulfillmentDisabled,
                );
            }

            if ($lockedRequest->type !== PrivacyRequestType::DataExport) {
                ApplicationValidation::fail(
                    'privacy_request',
                    ApplicationValidationCode::PrivacyExportRequestRequired,
                );
            }

            if ($lockedRequest->status !== PrivacyRequestStatus::Approved) {
                ApplicationValidation::fail(
                    'privacy_request',
                    ApplicationValidationCode::PrivacyRequestNotApproved,
                );
            }

            $eventResult = $this->events->append(
                request: $lockedRequest,
                actor: $lockedOperator,
                actorType: PrivacyRequestActorType::Operator,
                nextStatus: PrivacyRequestStatus::Fulfilled,
                reasonCode: 'export_delivered',
                note: $note,
                evidenceReference: $deliveryEvidenceReference,
                idempotencyKey: $idempotencyKey,
                expectedCurrentEventId: $expectedCurrentEventId,
            );
            $completedAt = CarbonImmutable::now();
            $fulfillment = PrivacyRequestFulfillment::query()->create([
                'privacy_request_id' => $lockedRequest->getKey(),
                'completion_event_id' => $eventResult['event']->getKey(),
                'actor_user_id' => $lockedOperator->getKey(),
                'request_type' => $lockedRequest->type,
                'execution_version' => $executionVersion,
                'data_inventory_version' => $dataInventoryVersion,
                'identity_evidence_reference' => (
                    $identityEvidenceReference
                ),
                'artifact_reference' => $artifactReference,
                'artifact_sha256' => $artifactSha256,
                'artifact_size_bytes' => $artifactSizeBytes,
                'artifact_expires_at' => $artifactExpiresAt,
                'delivery_evidence_reference' => (
                    $deliveryEvidenceReference
                ),
                'clearance_references' => null,
                'idempotency_key' => $idempotencyKey,
                'payload_hash' => $payloadHash,
                'completed_at' => $completedAt,
                'created_at' => $completedAt,
            ]);

            $this->audit->record(
                actor: $lockedOperator,
                action: 'privacy_request.export_fulfilled',
                subject: $lockedRequest,
                reason: $note,
                oldValues: [
                    'status' => PrivacyRequestStatus::Approved->value,
                    'current_event_id' => $expectedCurrentEventId,
                ],
                newValues: [
                    'status' => PrivacyRequestStatus::Fulfilled->value,
                    'current_event_id' => $eventResult['event']->getKey(),
                    'fulfillment_id' => $fulfillment->getKey(),
                    'execution_version' => $executionVersion,
                    'data_inventory_version' => $dataInventoryVersion,
                    'artifact_sha256' => $artifactSha256,
                    'artifact_size_bytes' => $artifactSizeBytes,
                    'artifact_expires_at' => (
                        $artifactExpiresAt->toIso8601String()
                    ),
                ],
            );

            return [
                'privacy_request' => $lockedRequest->fresh(),
                'fulfillment' => $fulfillment,
                'receipt_created' => true,
            ];
        }, attempts: 3);
    }

    private function validateStructuralInput(
        string $idempotencyKey,
        string $identityEvidenceReference,
        string $artifactReference,
        string $artifactSha256,
        string $deliveryEvidenceReference,
        string $note,
    ): void {
        if (! Str::isUuid($idempotencyKey)) {
            throw new InvalidArgumentException(
                'The fulfillment idempotency key must be a UUID.',
            );
        }

        $this->validateReference(
            $identityEvidenceReference,
            'identity evidence reference',
        );
        $this->validateReference(
            $artifactReference,
            'private artifact reference',
        );
        $this->validateReference(
            $deliveryEvidenceReference,
            'delivery evidence reference',
        );

        if (! preg_match('/^[a-f0-9]{64}$/', $artifactSha256)) {
            throw new InvalidArgumentException(
                'The export artifact SHA-256 must contain 64 hexadecimal characters.',
            );
        }

        if (mb_strlen($note) < 10 || mb_strlen($note) > 1000) {
            throw new InvalidArgumentException(
                'The fulfillment note must contain between 10 and 1000 characters.',
            );
        }
    }

    private function validateNewFulfillment(
        string $dataInventoryVersion,
        int $artifactSizeBytes,
        CarbonImmutable $artifactExpiresAt,
    ): void {
        if (
            $dataInventoryVersion
                !== $this->configuration->dataInventoryVersion()
        ) {
            ApplicationValidation::fail(
                'data_inventory_version',
                ApplicationValidationCode::PrivacyExportInventoryVersionMismatch,
            );
        }

        if (
            $artifactSizeBytes < 1
            || $artifactSizeBytes
                > $this->configuration->maximumExportBytes()
        ) {
            ApplicationValidation::fail(
                'artifact_size_bytes',
                ApplicationValidationCode::PrivacyExportArtifactSizeInvalid,
                [
                    'max' => (
                        $this->configuration->maximumExportBytes()
                    ),
                ],
            );
        }

        $now = CarbonImmutable::now();

        if (
            ! $artifactExpiresAt->greaterThan($now)
            || $artifactExpiresAt->greaterThan(
                $now->addDays(
                    $this->configuration
                        ->maximumArtifactRetentionDays(),
                ),
            )
        ) {
            ApplicationValidation::fail(
                'artifact_expires_at',
                ApplicationValidationCode::PrivacyExportArtifactExpiryInvalid,
                [
                    'days' => (
                        $this->configuration
                            ->maximumArtifactRetentionDays()
                    ),
                ],
            );
        }
    }

    private function validateReference(string $value, string $label): void
    {
        if (
            mb_strlen($value) < 3
            || mb_strlen($value) > 255
            || preg_match('/[\x00-\x1F\x7F]/u', $value)
        ) {
            throw new InvalidArgumentException(
                "The {$label} must contain 3 to 255 printable characters.",
            );
        }
    }

    /**
     * @throws JsonException
     */
    private function payloadHash(
        PrivacyRequest $request,
        User $operator,
        string $expectedCurrentEventId,
        string $idempotencyKey,
        string $executionVersion,
        string $dataInventoryVersion,
        string $identityEvidenceReference,
        string $artifactReference,
        string $artifactSha256,
        int $artifactSizeBytes,
        CarbonImmutable $artifactExpiresAt,
        string $deliveryEvidenceReference,
        string $note,
    ): string {
        return hash('sha256', json_encode([
            'privacy_request_id' => $request->getKey(),
            'operator_user_id' => $operator->getKey(),
            'expected_current_event_id' => $expectedCurrentEventId,
            'idempotency_key' => $idempotencyKey,
            'execution_version' => $executionVersion,
            'data_inventory_version' => $dataInventoryVersion,
            'identity_evidence_reference' => (
                $identityEvidenceReference
            ),
            'artifact_reference' => $artifactReference,
            'artifact_sha256' => $artifactSha256,
            'artifact_size_bytes' => $artifactSizeBytes,
            'artifact_expires_at' => (
                $artifactExpiresAt->utc()->toIso8601String()
            ),
            'delivery_evidence_reference' => (
                $deliveryEvidenceReference
            ),
            'note' => $note,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
