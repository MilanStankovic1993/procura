<?php

namespace App\Models;

use App\Enums\BrokerRequests\BrokerReportStatus;
use App\Enums\Localization\SupportedLocale;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class BrokerReport extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'id',
        'organization_id',
        'broker_request_id',
        'broker_request_offer_id',
        'broker_transaction_id',
        'broker_commission_id',
        'generated_by_user_id',
        'status',
        'report_version',
        'locale',
        'sequence',
        'source_transaction_event_id',
        'source_commission_event_id',
        'current_event_id',
        'event_sequence',
        'idempotency_key',
        'generation_payload_hash',
        'disk',
        'path',
        'filename',
        'mime_type',
        'artifact_sha256',
        'artifact_size_bytes',
        'page_count',
        'report_hash',
        'report_snapshot',
        'generated_at',
        'artifact_expires_at',
        'purged_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => BrokerReportStatus::class,
            'locale' => SupportedLocale::class,
            'sequence' => 'integer',
            'event_sequence' => 'integer',
            'artifact_size_bytes' => 'integer',
            'page_count' => 'integer',
            'report_snapshot' => 'array',
            'generated_at' => 'immutable_datetime',
            'artifact_expires_at' => 'immutable_datetime',
            'purged_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (BrokerReport $report): void {
            if ($report->isDirty([
                'organization_id',
                'broker_request_id',
                'broker_request_offer_id',
                'broker_transaction_id',
                'broker_commission_id',
                'generated_by_user_id',
                'report_version',
                'locale',
                'sequence',
                'source_transaction_event_id',
                'source_commission_event_id',
                'idempotency_key',
                'generation_payload_hash',
                'disk',
                'path',
                'filename',
                'mime_type',
                'artifact_sha256',
                'artifact_size_bytes',
                'page_count',
                'report_hash',
                'report_snapshot',
                'generated_at',
                'artifact_expires_at',
            ])) {
                throw new LogicException(
                    'Generated broker-report evidence is immutable.',
                );
            }
        });
        static::deleting(static function (): never {
            throw new LogicException(
                'Generated broker-report evidence cannot be deleted.',
            );
        });
    }

    public function brokerRequest(): BelongsTo
    {
        return $this->belongsTo(BrokerRequest::class);
    }

    public function brokerRequestOffer(): BelongsTo
    {
        return $this->belongsTo(BrokerRequestOffer::class);
    }

    public function brokerTransaction(): BelongsTo
    {
        return $this->belongsTo(BrokerTransaction::class);
    }

    public function brokerCommission(): BelongsTo
    {
        return $this->belongsTo(BrokerCommission::class);
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by_user_id');
    }

    public function sourceTransactionEvent(): BelongsTo
    {
        return $this->belongsTo(
            BrokerTransactionEvent::class,
            'source_transaction_event_id',
        );
    }

    public function sourceCommissionEvent(): BelongsTo
    {
        return $this->belongsTo(
            BrokerCommissionEvent::class,
            'source_commission_event_id',
        );
    }

    public function currentEvent(): BelongsTo
    {
        return $this->belongsTo(BrokerReportEvent::class, 'current_event_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(BrokerReportEvent::class)
            ->orderByDesc('sequence');
    }
}
