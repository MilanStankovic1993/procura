<?php

namespace App\Actions\BrokerRequests;

use App\BrokerRequests\BrokerReportConfiguration;
use App\BrokerRequests\BrokerReportRenderer;
use App\BrokerRequests\BrokerReportSnapshot;
use App\BrokerRequests\BrokerRequestInput;
use App\BrokerRequests\Data\BrokerReportWriteResult;
use App\Enums\BrokerRequests\BrokerCommissionStatus;
use App\Enums\BrokerRequests\BrokerReportEventType;
use App\Enums\BrokerRequests\BrokerReportStatus;
use App\Enums\BrokerRequests\BrokerTransactionStatus;
use App\Enums\Localization\SupportedLocale;
use App\Enums\Validation\ApplicationValidationCode;
use App\Models\BrokerReport;
use App\Models\BrokerTransaction;
use App\Models\User;
use App\Support\Validation\ApplicationValidation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class GenerateBrokerReport
{
    private const MAX_REPORT_HISTORY = 100;

    public function __construct(
        private readonly BrokerReportRenderer $renderer,
    ) {}

    public function execute(
        BrokerTransaction $transaction,
        User $actor,
        string $expectedTransactionEventId,
        string $expectedCommissionEventId,
        string $idempotencyKey,
        SupportedLocale $locale,
        string $reasonCode,
        string $evidenceReference,
    ): BrokerReportWriteResult {
        $configuration = BrokerReportConfiguration::load();
        $configuration->assertEnabled();
        $this->assertOperator($actor);
        $reasonCode = trim($reasonCode);
        $evidenceReference = trim($evidenceReference);
        $this->assertInput(
            $expectedTransactionEventId,
            $expectedCommissionEventId,
            $idempotencyKey,
            $reasonCode,
            $evidenceReference,
        );

        $payloadHash = BrokerRequestInput::hash([
            'operation' => BrokerReportEventType::Generated->value,
            'broker_transaction_id' => $transaction->getKey(),
            'expected_transaction_event_id' => $expectedTransactionEventId,
            'expected_commission_event_id' => $expectedCommissionEventId,
            'report_version' => $configuration->version,
            'locale' => $locale->value,
            'reason_code' => $reasonCode,
            'evidence_reference' => $evidenceReference,
        ]);
        $existing = BrokerReport::query()
            ->where('broker_transaction_id', $transaction->getKey())
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            $this->assertReplay($existing, $payloadHash);

            return new BrokerReportWriteResult(
                report: $existing,
                event: $existing->currentEvent()->firstOrFail(),
                created: false,
            );
        }

        $source = BrokerTransaction::query()
            ->forOrganization($transaction->organization_id)
            ->with([
                'brokerRequest',
                'brokerRequestOffer',
                'events',
                'commission',
            ])
            ->findOrFail($transaction->getKey());
        $this->assertSource(
            $source,
            $expectedTransactionEventId,
            $expectedCommissionEventId,
        );
        $generatedAt = now();
        $snapshot = BrokerReportSnapshot::build(
            request: $source->brokerRequest,
            offer: $source->brokerRequestOffer,
            transaction: $source,
            commission: $source->commission,
            version: $configuration->version,
            locale: $locale->value,
            generatedAt: $generatedAt->toIso8601String(),
        );
        $reportHash = BrokerRequestInput::hash($snapshot);
        $rendered = $this->renderer->render($snapshot, $locale);

        if (
            strlen($rendered->bytes) > $configuration->maxBytes
            || $rendered->pageCount < 1
            || $rendered->pageCount > 100
        ) {
            ApplicationValidation::fail(
                'broker_report',
                ApplicationValidationCode::BrokerReportArtifactTooLarge,
            );
        }

        $reportId = (string) Str::ulid();
        $path = sprintf(
            'broker-reports/%s/%s/%s.pdf',
            $source->organization_id,
            $source->getKey(),
            $reportId,
        );
        $stored = false;

        try {
            return DB::transaction(function () use (
                $source,
                $actor,
                $expectedTransactionEventId,
                $expectedCommissionEventId,
                $idempotencyKey,
                $locale,
                $reasonCode,
                $evidenceReference,
                $payloadHash,
                $configuration,
                $generatedAt,
                $snapshot,
                $reportHash,
                $rendered,
                $reportId,
                $path,
                &$stored,
            ): BrokerReportWriteResult {
                $locked = BrokerTransaction::query()
                    ->forOrganization($source->organization_id)
                    ->lockForUpdate()
                    ->findOrFail($source->getKey());
                $commission = $locked->commission()
                    ->lockForUpdate()
                    ->firstOrFail();
                $existing = BrokerReport::query()
                    ->where('broker_transaction_id', $locked->getKey())
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing !== null) {
                    $this->assertReplay($existing, $payloadHash);

                    return new BrokerReportWriteResult(
                        report: $existing,
                        event: $existing->currentEvent()->firstOrFail(),
                        created: false,
                    );
                }

                $this->assertLockedSource(
                    $locked,
                    $commission->status,
                    $commission->current_event_id,
                    $expectedTransactionEventId,
                    $expectedCommissionEventId,
                );

                if (
                    BrokerReport::query()
                        ->where('broker_transaction_id', $locked->getKey())
                        ->where(
                            'source_transaction_event_id',
                            $expectedTransactionEventId,
                        )
                        ->where(
                            'source_commission_event_id',
                            $expectedCommissionEventId,
                        )
                        ->where('report_version', $configuration->version)
                        ->where('locale', $locale->value)
                        ->exists()
                ) {
                    ApplicationValidation::fail(
                        'idempotency_key',
                        ApplicationValidationCode::BrokerReportIdempotencyConflict,
                    );
                }

                $sequence = (int) BrokerReport::query()
                    ->where('broker_transaction_id', $locked->getKey())
                    ->max('sequence') + 1;

                if ($sequence > self::MAX_REPORT_HISTORY) {
                    ApplicationValidation::fail(
                        'broker_report',
                        ApplicationValidationCode::BrokerReportHistoryLimit,
                    );
                }

                $disk = Storage::disk($configuration->disk);
                if (! $disk->put(
                    $path,
                    $rendered->bytes,
                    ['visibility' => 'private'],
                )) {
                    throw new RuntimeException(
                        'The broker-report artifact could not be stored.',
                    );
                }
                $stored = true;
                $storedBytes = $disk->get($path);

                if (
                    strlen($storedBytes) !== strlen($rendered->bytes)
                    || ! hash_equals(
                        hash('sha256', $rendered->bytes),
                        hash('sha256', $storedBytes),
                    )
                ) {
                    throw new RuntimeException(
                        'The stored broker-report artifact failed verification.',
                    );
                }

                $report = BrokerReport::query()->create([
                    'id' => $reportId,
                    'organization_id' => $locked->organization_id,
                    'broker_request_id' => $locked->broker_request_id,
                    'broker_request_offer_id' => (
                        $locked->broker_request_offer_id
                    ),
                    'broker_transaction_id' => $locked->getKey(),
                    'broker_commission_id' => $commission->getKey(),
                    'generated_by_user_id' => $actor->getKey(),
                    'status' => BrokerReportStatus::Available,
                    'report_version' => $configuration->version,
                    'locale' => $locale,
                    'sequence' => $sequence,
                    'source_transaction_event_id' => (
                        $expectedTransactionEventId
                    ),
                    'source_commission_event_id' => (
                        $expectedCommissionEventId
                    ),
                    'event_sequence' => 0,
                    'idempotency_key' => $idempotencyKey,
                    'generation_payload_hash' => $payloadHash,
                    'disk' => $configuration->disk,
                    'path' => $path,
                    'filename' => "procura-broker-report-{$reportId}.pdf",
                    'mime_type' => 'application/pdf',
                    'artifact_sha256' => hash(
                        'sha256',
                        $rendered->bytes,
                    ),
                    'artifact_size_bytes' => strlen($rendered->bytes),
                    'page_count' => $rendered->pageCount,
                    'report_hash' => $reportHash,
                    'report_snapshot' => $snapshot,
                    'generated_at' => $generatedAt,
                    'artifact_expires_at' => $generatedAt->copy()->addDays(
                        $configuration->retentionDays,
                    ),
                ]);
                $event = $report->events()->create([
                    'organization_id' => $report->organization_id,
                    'broker_request_id' => $report->broker_request_id,
                    'broker_request_offer_id' => (
                        $report->broker_request_offer_id
                    ),
                    'broker_transaction_id' => (
                        $report->broker_transaction_id
                    ),
                    'broker_commission_id' => $report->broker_commission_id,
                    'actor_user_id' => $actor->getKey(),
                    'sequence' => 1,
                    'event_type' => BrokerReportEventType::Generated,
                    'from_status' => null,
                    'to_status' => BrokerReportStatus::Available,
                    'reason_code' => $reasonCode,
                    'evidence_reference' => $evidenceReference,
                    'idempotency_key' => $idempotencyKey,
                    'payload_hash' => $payloadHash,
                    'report_hash' => $reportHash,
                    'report_snapshot' => $snapshot,
                    'occurred_at' => $generatedAt,
                ]);
                $report->forceFill([
                    'current_event_id' => $event->getKey(),
                    'event_sequence' => 1,
                ])->save();

                return new BrokerReportWriteResult(
                    report: $report,
                    event: $event,
                    created: true,
                );
            }, attempts: 3);
        } catch (Throwable $exception) {
            if ($stored) {
                Storage::disk($configuration->disk)->delete($path);
            }

            throw $exception;
        }
    }

    private function assertInput(
        string $transactionEventId,
        string $commissionEventId,
        string $idempotencyKey,
        string $reasonCode,
        string $evidenceReference,
    ): void {
        if ($evidenceReference === '') {
            ApplicationValidation::fail(
                'evidence_reference',
                ApplicationValidationCode::BrokerReportOperatorEvidenceRequired,
            );
        }

        if (
            ! Str::isUlid($transactionEventId)
            || ! Str::isUlid($commissionEventId)
            || ! Str::isUuid($idempotencyKey)
            || preg_match('/^[a-z0-9][a-z0-9._-]{2,63}$/', $reasonCode) !== 1
            || mb_strlen($evidenceReference) > 255
        ) {
            throw new InvalidArgumentException(
                'The broker-report evidence or source identifiers are invalid.',
            );
        }
    }

    private function assertSource(
        BrokerTransaction $transaction,
        string $transactionEventId,
        string $commissionEventId,
    ): void {
        $this->assertLockedSource(
            $transaction,
            $transaction->commission->status,
            $transaction->commission->current_event_id,
            $transactionEventId,
            $commissionEventId,
        );
    }

    private function assertLockedSource(
        BrokerTransaction $transaction,
        BrokerCommissionStatus $commissionStatus,
        ?string $commissionEventId,
        string $expectedTransactionEventId,
        string $expectedCommissionEventId,
    ): void {
        if ($transaction->status !== BrokerTransactionStatus::Completed) {
            ApplicationValidation::fail(
                'broker_transaction',
                ApplicationValidationCode::BrokerReportTransactionStateInvalid,
            );
        }
        if (
            ! in_array(
                $commissionStatus,
                [
                    BrokerCommissionStatus::Earned,
                    BrokerCommissionStatus::Settled,
                ],
                true,
            )
        ) {
            ApplicationValidation::fail(
                'broker_commission',
                ApplicationValidationCode::BrokerReportCommissionStateInvalid,
            );
        }
        if ($transaction->current_event_id !== $expectedTransactionEventId) {
            ApplicationValidation::fail(
                'expected_transaction_event_id',
                ApplicationValidationCode::BrokerReportTransactionStale,
            );
        }
        if ($commissionEventId !== $expectedCommissionEventId) {
            ApplicationValidation::fail(
                'expected_commission_event_id',
                ApplicationValidationCode::BrokerReportCommissionStale,
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
        BrokerReport $report,
        string $payloadHash,
    ): void {
        if (! hash_equals($report->generation_payload_hash, $payloadHash)) {
            ApplicationValidation::fail(
                'idempotency_key',
                ApplicationValidationCode::BrokerReportIdempotencyConflict,
            );
        }
    }
}
