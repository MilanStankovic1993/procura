<?php

namespace App\Actions\Privacy;

use App\Actions\Administration\RecordPlatformAuditEvent;
use App\Enums\Api\ApiErrorCode;
use App\Enums\Localization\SupportedLocale;
use App\Enums\Privacy\PrivacyRequestActorType;
use App\Enums\Privacy\PrivacyRequestStatus;
use App\Enums\Privacy\PrivacyRequestType;
use App\Enums\Validation\ApplicationValidationCode;
use App\Exceptions\PrivacyRequestConflictException;
use App\Models\PrivacyRequest;
use App\Models\PrivacyRequestFulfillment;
use App\Models\User;
use App\Privacy\AccountDeletionBlockerResolver;
use App\Privacy\PrivacyWorkflowConfiguration;
use App\Support\Validation\ApplicationValidation;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use Throwable;

final class CompletePrivacyAccountErasure
{
    public function __construct(
        private readonly PrivacyRequestEventRecorder $events,
        private readonly RecordPlatformAuditEvent $audit,
        private readonly PrivacyWorkflowConfiguration $configuration,
        private readonly AccountDeletionBlockerResolver $blockers,
    ) {}

    /**
     * @param  array<string, string>  $clearanceReferences
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
        string $erasureEvidenceReference,
        string $storageEvidenceReference,
        string $processorEvidenceReference,
        string $completionEvidenceReference,
        array $clearanceReferences,
        CarbonImmutable $backupPurgeDueAt,
        string $note,
    ): array {
        $expectedCurrentEventId = trim($expectedCurrentEventId);
        $idempotencyKey = trim($idempotencyKey);
        $dataInventoryVersion = trim($dataInventoryVersion);
        $identityEvidenceReference = trim($identityEvidenceReference);
        $erasureEvidenceReference = trim($erasureEvidenceReference);
        $storageEvidenceReference = trim($storageEvidenceReference);
        $processorEvidenceReference = trim($processorEvidenceReference);
        $completionEvidenceReference = trim(
            $completionEvidenceReference,
        );
        $note = trim($note);
        $clearanceReferences = $this->normalizeClearances(
            $clearanceReferences,
        );

        $this->validateStructuralInput(
            $idempotencyKey,
            $identityEvidenceReference,
            $erasureEvidenceReference,
            $storageEvidenceReference,
            $processorEvidenceReference,
            $completionEvidenceReference,
            $note,
        );

        return DB::transaction(function () use (
            $request,
            $operator,
            $expectedCurrentEventId,
            $idempotencyKey,
            $dataInventoryVersion,
            $identityEvidenceReference,
            $erasureEvidenceReference,
            $storageEvidenceReference,
            $processorEvidenceReference,
            $completionEvidenceReference,
            $clearanceReferences,
            $backupPurgeDueAt,
            $note,
        ): array {
            $lockedOperator = User::query()
                ->lockForUpdate()
                ->findOrFail($operator->getKey());

            if (
                ! $lockedOperator->is_super_admin
                || ! $lockedOperator->hasVerifiedEmail()
                || $lockedOperator->privacy_erased_at !== null
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
                ?? $this->configuration->erasureExecutionVersion();
            $payloadHash = $this->payloadHash(
                $lockedRequest,
                $lockedOperator,
                $expectedCurrentEventId,
                $idempotencyKey,
                $executionVersion,
                $dataInventoryVersion,
                $identityEvidenceReference,
                $erasureEvidenceReference,
                $storageEvidenceReference,
                $processorEvidenceReference,
                $completionEvidenceReference,
                $clearanceReferences,
                $backupPurgeDueAt,
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

            $this->validateNewErasure(
                $lockedRequest,
                $dataInventoryVersion,
                $clearanceReferences,
                $backupPurgeDueAt,
            );

            $subject = User::query()
                ->lockForUpdate()
                ->find($lockedRequest->subject_user_id);

            if (
                $subject === null
                || $subject->privacy_erased_at !== null
            ) {
                ApplicationValidation::fail(
                    'privacy_request',
                    ApplicationValidationCode::PrivacyErasureSubjectUnavailable,
                );
            }

            $memberOrganizationIds = DB::table('organization_user')
                ->where('user_id', $subject->getKey())
                ->lockForUpdate()
                ->pluck('organization_id');

            if ($memberOrganizationIds->isNotEmpty()) {
                DB::table('organizations')
                    ->whereIn('id', $memberOrganizationIds)
                    ->lockForUpdate()
                    ->get(['id']);
                DB::table('subscriptions')
                    ->whereIn('organization_id', $memberOrganizationIds)
                    ->lockForUpdate()
                    ->get(['id']);
            }

            $liveBlockers = $this->blockers->live($subject);

            if ($liveBlockers !== []) {
                ApplicationValidation::fail(
                    'privacy_request',
                    ApplicationValidationCode::PrivacyErasureLiveBlockers,
                    ['blockers' => implode(', ', $liveBlockers)],
                );
            }

            $personalOrganizationId = DB::table('organizations')
                ->where('personal_user_id', $subject->getKey())
                ->lockForUpdate()
                ->value('id');
            $remainingFiles = $personalOrganizationId === null
                ? 0
                : $this->remainingPrivateFileCount(
                    (string) $personalOrganizationId,
                );

            if ($remainingFiles > 0) {
                ApplicationValidation::fail(
                    'privacy_request',
                    ApplicationValidationCode::PrivacyErasurePrivateFilesRemain,
                    ['count' => $remainingFiles],
                );
            }

            $completedAt = CarbonImmutable::now();
            $eventResult = $this->events->append(
                request: $lockedRequest,
                actor: $lockedOperator,
                actorType: PrivacyRequestActorType::Operator,
                nextStatus: PrivacyRequestStatus::Fulfilled,
                reasonCode: 'account_erased',
                note: $note,
                evidenceReference: $completionEvidenceReference,
                idempotencyKey: $idempotencyKey,
                expectedCurrentEventId: $expectedCurrentEventId,
            );
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
                'delivery_evidence_reference' => (
                    $completionEvidenceReference
                ),
                'erasure_evidence_reference' => (
                    $erasureEvidenceReference
                ),
                'storage_evidence_reference' => (
                    $storageEvidenceReference
                ),
                'processor_evidence_reference' => (
                    $processorEvidenceReference
                ),
                'backup_purge_due_at' => $backupPurgeDueAt,
                'clearance_references' => $clearanceReferences,
                'idempotency_key' => $idempotencyKey,
                'payload_hash' => $payloadHash,
                'completed_at' => $completedAt,
                'created_at' => $completedAt,
            ]);

            $this->eraseAccountData(
                $subject,
                $lockedRequest,
                $personalOrganizationId === null
                    ? null
                    : (string) $personalOrganizationId,
                $completedAt,
            );

            $this->audit->record(
                actor: $lockedOperator,
                action: 'privacy_request.account_erased',
                subject: $lockedRequest,
                reason: $note,
                newValues: [
                    'status' => PrivacyRequestStatus::Fulfilled->value,
                    'current_event_id' => $eventResult['event']->getKey(),
                    'fulfillment_id' => $fulfillment->getKey(),
                    'execution_version' => $executionVersion,
                    'data_inventory_version' => $dataInventoryVersion,
                    'clearance_codes' => array_keys(
                        $clearanceReferences,
                    ),
                    'backup_purge_due_at' => (
                        $backupPurgeDueAt->utc()->toIso8601String()
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

    /**
     * @param  array<string, string>  $clearanceReferences
     */
    private function validateNewErasure(
        PrivacyRequest $request,
        string $dataInventoryVersion,
        array $clearanceReferences,
        CarbonImmutable $backupPurgeDueAt,
    ): void {
        if (! $this->configuration->erasureEnabled()) {
            ApplicationValidation::fail(
                'privacy_request',
                ApplicationValidationCode::PrivacyErasureDisabled,
            );
        }

        if ($request->type !== PrivacyRequestType::AccountDeletion) {
            ApplicationValidation::fail(
                'privacy_request',
                ApplicationValidationCode::PrivacyDeletionRequestRequired,
            );
        }

        if ($request->status !== PrivacyRequestStatus::Approved) {
            ApplicationValidation::fail(
                'privacy_request',
                ApplicationValidationCode::PrivacyRequestNotApproved,
            );
        }

        if (
            ! hash_equals(
                $this->configuration->erasureDataInventoryVersion(),
                $dataInventoryVersion,
            )
        ) {
            ApplicationValidation::fail(
                'data_inventory_version',
                ApplicationValidationCode::PrivacyErasureInventoryVersionMismatch,
            );
        }

        $requiredClearances = array_values(array_unique(
            $request->blocking_reason_codes ?? [],
        ));
        sort($requiredClearances);

        if ($requiredClearances !== array_keys($clearanceReferences)) {
            ApplicationValidation::fail(
                'clearance_references',
                ApplicationValidationCode::PrivacyErasureClearanceInvalid,
            );
        }

        $now = CarbonImmutable::now();

        if (
            ! $backupPurgeDueAt->greaterThan($now)
            || $backupPurgeDueAt->greaterThan(
                $now->addDays(
                    $this->configuration
                        ->maximumBackupRetentionDays(),
                ),
            )
        ) {
            ApplicationValidation::fail(
                'backup_purge_due_at',
                ApplicationValidationCode::PrivacyErasureBackupDeadlineInvalid,
                [
                    'days' => (
                        $this->configuration
                            ->maximumBackupRetentionDays()
                    ),
                ],
            );
        }
    }

    private function eraseAccountData(
        User $subject,
        PrivacyRequest $request,
        ?string $personalOrganizationId,
        CarbonImmutable $completedAt,
    ): void {
        $subjectId = $subject->getKey();
        $originalEmail = mb_strtolower(trim($subject->email));

        DB::table('saved_searches')
            ->where('owner_user_id', $subjectId)
            ->delete();
        DB::table('alerts')
            ->where('recipient_user_id', $subjectId)
            ->delete();

        if ($personalOrganizationId !== null) {
            DB::table('organizations')
                ->where('id', $personalOrganizationId)
                ->delete();
        }

        DB::table('organization_user')
            ->where('user_id', $subjectId)
            ->delete();

        $invitationIds = DB::table('organization_invitations')
            ->whereRaw('LOWER(email) = ?', [$originalEmail])
            ->orWhereRaw('LOWER(pending_email) = ?', [$originalEmail])
            ->pluck('id');

        foreach ($invitationIds as $invitationId) {
            DB::table('organization_invitations')
                ->where('id', $invitationId)
                ->update([
                    'email' => (
                        "erased-invitation-{$invitationId}@privacy.invalid"
                    ),
                    'pending_email' => null,
                    'token_hash' => hash(
                        'sha256',
                        $invitationId.'|'.Str::uuid(),
                    ),
                    'revoked_at' => DB::raw(
                        'CASE WHEN accepted_at IS NULL '
                        .'THEN COALESCE(revoked_at, CURRENT_TIMESTAMP) '
                        .'ELSE revoked_at END',
                    ),
                    'updated_at' => $completedAt,
                ]);
        }

        DB::table('telegram_connections')
            ->where('user_id', $subjectId)
            ->delete();
        DB::table('sessions')
            ->where('user_id', $subjectId)
            ->delete();
        DB::table('personal_access_tokens')
            ->where('tokenable_type', $subject->getMorphClass())
            ->where('tokenable_id', $subjectId)
            ->delete();
        DB::table('password_reset_tokens')
            ->whereRaw('LOWER(email) = ?', [$originalEmail])
            ->delete();

        $subject->forceFill([
            'current_organization_id' => null,
            'name' => 'Erased account',
            'email' => 'erased-'.Str::uuid().'@privacy.invalid',
            'email_verified_at' => null,
            'is_super_admin' => false,
            'preferred_locale' => SupportedLocale::English,
            'password' => Str::random(80),
            'remember_token' => null,
            'privacy_erased_at' => $completedAt,
            'privacy_erasure_request_id' => $request->getKey(),
        ])->save();
    }

    private function remainingPrivateFileCount(
        string $organizationId,
    ): int {
        $listingIds = DB::table('listings')
            ->where('organization_id', $organizationId)
            ->lockForUpdate()
            ->pluck('id');
        $ownedProductIds = DB::table('owned_products')
            ->where('organization_id', $organizationId)
            ->lockForUpdate()
            ->pluck('id');
        $references = DB::table('listing_images')
            ->whereIn('listing_id', $listingIds)
            ->lockForUpdate()
            ->select('disk', 'path')
            ->get()
            ->concat(
                DB::table('owned_product_images')
                    ->whereIn('owned_product_id', $ownedProductIds)
                    ->lockForUpdate()
                    ->select('disk', 'path')
                    ->get(),
            )
            ->concat(
                DB::table('marketplace_imports')
                    ->where('organization_id', $organizationId)
                    ->lockForUpdate()
                    ->select('disk', 'path')
                    ->get(),
            )
            ->concat(
                DB::table('broker_reports')
                    ->where('organization_id', $organizationId)
                    ->where('status', 'available')
                    ->lockForUpdate()
                    ->select('disk', 'path')
                    ->get(),
            )
            ->unique(
                static fn (object $reference): string => (
                    $reference->disk.'|'.$reference->path
                ),
            );
        $remaining = 0;

        foreach ($references as $reference) {
            $disk = trim((string) $reference->disk);
            $path = trim((string) $reference->path);

            try {
                if (
                    $disk === ''
                    || $path === ''
                    || Storage::disk($disk)->exists($path)
                ) {
                    $remaining++;
                }
            } catch (Throwable) {
                $remaining++;
            }
        }

        return $remaining;
    }

    /**
     * @param  array<string, string>  $clearanceReferences
     * @return array<string, string>
     */
    private function normalizeClearances(
        array $clearanceReferences,
    ): array {
        $normalized = [];

        foreach ($clearanceReferences as $code => $reference) {
            if (
                ! is_string($code)
                || ! is_string($reference)
                || ! preg_match('/\A[a-z0-9_]{3,64}\z/', $code)
            ) {
                throw new InvalidArgumentException(
                    'Clearances must use blocker_code=evidence_reference.',
                );
            }

            $reference = trim($reference);
            $this->validateReference($reference, 'clearance reference');
            $normalized[$code] = $reference;
        }

        ksort($normalized);

        return $normalized;
    }

    private function validateStructuralInput(
        string $idempotencyKey,
        string $identityEvidenceReference,
        string $erasureEvidenceReference,
        string $storageEvidenceReference,
        string $processorEvidenceReference,
        string $completionEvidenceReference,
        string $note,
    ): void {
        if (! Str::isUuid($idempotencyKey)) {
            throw new InvalidArgumentException(
                'The fulfillment idempotency key must be a UUID.',
            );
        }

        foreach ([
            'identity evidence reference' => (
                $identityEvidenceReference
            ),
            'erasure evidence reference' => (
                $erasureEvidenceReference
            ),
            'storage evidence reference' => (
                $storageEvidenceReference
            ),
            'processor evidence reference' => (
                $processorEvidenceReference
            ),
            'completion evidence reference' => (
                $completionEvidenceReference
            ),
        ] as $label => $reference) {
            $this->validateReference($reference, $label);
        }

        if (
            mb_strlen($note) < 10
            || mb_strlen($note) > 1000
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $note)
        ) {
            throw new InvalidArgumentException(
                'The fulfillment note must contain between 10 and 1000 characters.',
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
     * @param  array<string, string>  $clearanceReferences
     *
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
        string $erasureEvidenceReference,
        string $storageEvidenceReference,
        string $processorEvidenceReference,
        string $completionEvidenceReference,
        array $clearanceReferences,
        CarbonImmutable $backupPurgeDueAt,
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
            'erasure_evidence_reference' => (
                $erasureEvidenceReference
            ),
            'storage_evidence_reference' => (
                $storageEvidenceReference
            ),
            'processor_evidence_reference' => (
                $processorEvidenceReference
            ),
            'completion_evidence_reference' => (
                $completionEvidenceReference
            ),
            'clearance_references' => $clearanceReferences,
            'backup_purge_due_at' => (
                $backupPurgeDueAt->utc()->toIso8601String()
            ),
            'note' => $note,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
