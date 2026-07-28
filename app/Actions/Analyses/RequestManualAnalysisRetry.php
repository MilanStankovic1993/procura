<?php

namespace App\Actions\Analyses;

use App\Actions\Administration\RecordPlatformAuditEvent;
use App\Enums\Analyses\AnalysisDispatchStatus;
use App\Enums\Analyses\AnalysisStatus;
use App\Enums\Validation\ApplicationValidationCode;
use App\Exceptions\AnalysisRetryConflictException;
use App\Models\Analysis;
use App\Models\AnalysisDispatch;
use App\Models\AnalysisRetryEvent;
use App\Models\User;
use App\Support\Validation\ApplicationValidation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;

final class RequestManualAnalysisRetry
{
    public function __construct(
        private readonly DispatchAnalysis $dispatcher,
        private readonly RecordPlatformAuditEvent $audit,
    ) {}

    /**
     * @return array{
     *     analysis: Analysis,
     *     dispatch: AnalysisDispatch,
     *     retry_event: AnalysisRetryEvent,
     *     created: bool
     * }
     *
     * @throws JsonException
     */
    public function request(
        Analysis $analysis,
        User $operator,
        string $expectedCurrentDispatchId,
        string $idempotencyKey,
        string $reason,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        $expectedCurrentDispatchId = trim($expectedCurrentDispatchId);
        $idempotencyKey = trim($idempotencyKey);
        $reason = trim($reason);
        $this->validateInput(
            $expectedCurrentDispatchId,
            $idempotencyKey,
            $reason,
        );
        $payloadHash = $this->payloadHash(
            $analysis,
            $operator,
            $expectedCurrentDispatchId,
            $reason,
        );

        $result = DB::transaction(function () use (
            $analysis,
            $operator,
            $expectedCurrentDispatchId,
            $idempotencyKey,
            $reason,
            $payloadHash,
            $ipAddress,
            $userAgent,
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

            if (! (bool) config('analyses.manual_retry_enabled')) {
                ApplicationValidation::fail(
                    'analysis',
                    ApplicationValidationCode::ManualAnalysisRetryDisabled,
                );
            }

            $lockedAnalysis = Analysis::query()
                ->lockForUpdate()
                ->findOrFail($analysis->getKey());
            $existing = AnalysisRetryEvent::query()
                ->where('analysis_id', $lockedAnalysis->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $this->assertReplay($existing, $payloadHash);

                return [
                    'analysis' => $lockedAnalysis->fresh(),
                    'dispatch' => AnalysisDispatch::query()
                        ->findOrFail($existing->new_dispatch_id),
                    'retry_event' => $existing,
                    'created' => false,
                ];
            }

            $currentDispatch = AnalysisDispatch::query()
                ->where('analysis_id', $lockedAnalysis->getKey())
                ->orderByDesc('run_number')
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $currentDispatch->getKey()
                !== $expectedCurrentDispatchId
            ) {
                throw new AnalysisRetryConflictException(
                    'analysis_retry_stale_dispatch',
                    'expected_current_dispatch_id',
                    'The analysis dispatch changed. Refresh operations before retrying.',
                );
            }

            $lockedAnalysis->setRelation(
                'currentDispatch',
                $currentDispatch,
            );

            if (! $this->isEligible($lockedAnalysis)) {
                ApplicationValidation::fail(
                    'analysis',
                    ApplicationValidationCode::ManualAnalysisRetryIneligible,
                );
            }

            $runNumber = $currentDispatch->run_number + 1;
            $maximumRuns = (int) config(
                'analyses.manual_retry_max_runs',
            );
            $additionalAttempts = (int) config(
                'analyses.manual_retry_attempts',
            );

            if (
                $maximumRuns < 1
                || $maximumRuns > 100
                || $additionalAttempts < 1
                || $additionalAttempts > 10
            ) {
                throw new InvalidArgumentException(
                    'Manual analysis retry configuration is invalid.',
                );
            }

            if ($runNumber > $maximumRuns) {
                ApplicationValidation::fail(
                    'analysis',
                    ApplicationValidationCode::ManualAnalysisRetryLimit,
                );
            }

            $maxProcessingAttempts = (
                $lockedAnalysis->processing_attempts
                + $additionalAttempts
            );
            $newDispatch = AnalysisDispatch::query()->create([
                'organization_id' => $lockedAnalysis->organization_id,
                'analysis_id' => $lockedAnalysis->getKey(),
                'run_number' => $runNumber,
                'pipeline_version' => $lockedAnalysis->pipeline_version,
                'dispatch_key' => sprintf(
                    'analysis:%s:run:%d',
                    $lockedAnalysis->getKey(),
                    $runNumber,
                ),
                'status' => AnalysisDispatchStatus::Pending,
                'queue_name' => config('analyses.queue'),
                'max_processing_attempts' => $maxProcessingAttempts,
                'available_at' => now(),
            ]);
            $retryEvent = AnalysisRetryEvent::query()->create([
                'organization_id' => $lockedAnalysis->organization_id,
                'analysis_id' => $lockedAnalysis->getKey(),
                'previous_dispatch_id' => $currentDispatch->getKey(),
                'new_dispatch_id' => $newDispatch->getKey(),
                'actor_user_id' => $lockedOperator->getKey(),
                'run_number' => $runNumber,
                'previous_processing_attempts' => (
                    $lockedAnalysis->processing_attempts
                ),
                'previous_failed_at' => $lockedAnalysis->failed_at,
                'previous_error_code' => (
                    $lockedAnalysis->last_error_code
                ),
                'previous_error_hash' => (
                    $lockedAnalysis->last_error_message === null
                        ? null
                        : hash(
                            'sha256',
                            $lockedAnalysis->last_error_message,
                        )
                ),
                'reason' => $reason,
                'idempotency_key' => $idempotencyKey,
                'payload_hash' => $payloadHash,
                'requested_at' => now(),
            ]);

            $lockedAnalysis->update([
                'status' => AnalysisStatus::Queued,
                'result_payload' => null,
                'processing_started_at' => null,
                'finished_at' => null,
                'failed_at' => null,
                'next_retry_at' => null,
                'last_error_code' => null,
                'last_error_message' => null,
            ]);
            $this->audit->record(
                actor: $lockedOperator,
                action: 'analysis.manual_retry_requested',
                subject: $lockedAnalysis,
                organization: $lockedAnalysis->organization,
                reason: $reason,
                oldValues: [
                    'status' => AnalysisStatus::Failed->value,
                    'dispatch_id' => $currentDispatch->getKey(),
                    'error_code' => $retryEvent->previous_error_code,
                    'processing_attempts' => (
                        $retryEvent->previous_processing_attempts
                    ),
                ],
                newValues: [
                    'status' => AnalysisStatus::Queued->value,
                    'dispatch_id' => $newDispatch->getKey(),
                    'retry_event_id' => $retryEvent->getKey(),
                    'run_number' => $runNumber,
                    'max_processing_attempts' => $maxProcessingAttempts,
                ],
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return [
                'analysis' => $lockedAnalysis->fresh(),
                'dispatch' => $newDispatch,
                'retry_event' => $retryEvent,
                'created' => true,
            ];
        }, attempts: 3);

        $result['dispatch'] = $this->dispatcher->dispatch(
            $result['dispatch'],
        );

        return $result;
    }

    public function isEligible(Analysis $analysis): bool
    {
        if (
            ! (bool) config('analyses.manual_retry_enabled')
            ||
            $analysis->status !== AnalysisStatus::Failed
            || $analysis->next_retry_at !== null
        ) {
            return false;
        }

        $dispatch = $analysis->relationLoaded('currentDispatch')
            ? $analysis->getRelation('currentDispatch')
            : $analysis->currentDispatch()->first();

        return
            $dispatch instanceof AnalysisDispatch
            && $dispatch->status === AnalysisDispatchStatus::Failed
            && $dispatch->available_at === null;
    }

    private function validateInput(
        string $expectedCurrentDispatchId,
        string $idempotencyKey,
        string $reason,
    ): void {
        if (! Str::isUlid($expectedCurrentDispatchId)) {
            throw new InvalidArgumentException(
                'The expected current dispatch must be a ULID.',
            );
        }

        if (! Str::isUuid($idempotencyKey)) {
            throw new InvalidArgumentException(
                'The idempotency key must be a UUID.',
            );
        }

        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 1000) {
            throw new InvalidArgumentException(
                'The manual retry reason must contain between 10 and 1000 characters.',
            );
        }
    }

    private function assertReplay(
        AnalysisRetryEvent $event,
        string $payloadHash,
    ): void {
        if ($event->payload_hash !== $payloadHash) {
            throw new AnalysisRetryConflictException(
                'analysis_retry_idempotency_conflict',
                'idempotency_key',
                'The idempotency key was already used with a different manual retry request.',
            );
        }
    }

    /**
     * @throws JsonException
     */
    private function payloadHash(
        Analysis $analysis,
        User $operator,
        string $expectedCurrentDispatchId,
        string $reason,
    ): string {
        return hash('sha256', json_encode([
            'analysis_id' => $analysis->getKey(),
            'operator_user_id' => $operator->getKey(),
            'expected_current_dispatch_id' => (
                $expectedCurrentDispatchId
            ),
            'reason' => $reason,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
