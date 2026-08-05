<?php

namespace App\Actions\BrokerRequests;

use App\BrokerRequests\BrokerReportConfiguration;
use App\BrokerRequests\BrokerRequestInput;
use App\BrokerRequests\Data\BrokerReportPurgeResult;
use App\Enums\BrokerRequests\BrokerReportEventType;
use App\Enums\BrokerRequests\BrokerReportStatus;
use App\Models\BrokerReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class PurgeExpiredBrokerReports
{
    public function execute(?int $limit = null): BrokerReportPurgeResult
    {
        $configuration = BrokerReportConfiguration::load();
        $limit = min(
            max($limit ?? $configuration->purgeBatch, 1),
            $configuration->purgeBatch,
        );
        $ids = BrokerReport::query()
            ->where('status', BrokerReportStatus::Available)
            ->where('artifact_expires_at', '<=', now())
            ->orderBy('artifact_expires_at')
            ->limit($limit)
            ->pluck('id');
        $purged = 0;
        $failed = 0;

        foreach ($ids as $id) {
            try {
                $changed = DB::transaction(function () use ($id): bool {
                    $report = BrokerReport::query()
                        ->lockForUpdate()
                        ->find($id);

                    if (
                        $report === null
                        || $report->status !== BrokerReportStatus::Available
                        || $report->artifact_expires_at->isFuture()
                    ) {
                        return false;
                    }

                    $disk = Storage::disk($report->disk);
                    if ($disk->exists($report->path)) {
                        if (! $disk->delete($report->path)) {
                            throw new \RuntimeException(
                                'Broker-report artifact deletion failed.',
                            );
                        }
                    }

                    $occurredAt = now();
                    $idempotency = (string) Str::uuid();
                    $payloadHash = BrokerRequestInput::hash([
                        'operation' => BrokerReportEventType::Purged->value,
                        'broker_report_id' => $report->getKey(),
                        'previous_event_id' => $report->current_event_id,
                        'reason_code' => 'artifact_retention_expired',
                        'occurred_at' => $occurredAt->toIso8601String(),
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
                        'broker_commission_id' => (
                            $report->broker_commission_id
                        ),
                        'previous_event_id' => $report->current_event_id,
                        'actor_user_id' => null,
                        'sequence' => $report->event_sequence + 1,
                        'event_type' => BrokerReportEventType::Purged,
                        'from_status' => BrokerReportStatus::Available,
                        'to_status' => BrokerReportStatus::Purged,
                        'reason_code' => 'artifact_retention_expired',
                        'evidence_reference' => null,
                        'idempotency_key' => $idempotency,
                        'payload_hash' => $payloadHash,
                        'report_hash' => $report->report_hash,
                        'report_snapshot' => $report->report_snapshot,
                        'occurred_at' => $occurredAt,
                    ]);
                    $report->forceFill([
                        'status' => BrokerReportStatus::Purged,
                        'current_event_id' => $event->getKey(),
                        'event_sequence' => $report->event_sequence + 1,
                        'purged_at' => $occurredAt,
                    ])->save();

                    return true;
                }, attempts: 3);
                $purged += $changed ? 1 : 0;
            } catch (Throwable) {
                $failed++;
            }
        }

        return new BrokerReportPurgeResult(
            processed: $ids->count(),
            purged: $purged,
            failed: $failed,
        );
    }
}
