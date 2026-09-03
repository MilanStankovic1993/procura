<?php

namespace App\Models;

use App\Enums\BrokerRequests\BrokerReportEventType;
use App\Enums\BrokerRequests\BrokerReportStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class BrokerReportEvent extends Model
{
    use BelongsToOrganization, HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id',
        'broker_request_id',
        'broker_request_offer_id',
        'broker_transaction_id',
        'broker_commission_id',
        'broker_report_id',
        'previous_event_id',
        'actor_user_id',
        'sequence',
        'event_type',
        'from_status',
        'to_status',
        'reason_code',
        'evidence_reference',
        'idempotency_key',
        'payload_hash',
        'report_hash',
        'report_snapshot',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'event_type' => BrokerReportEventType::class,
            'from_status' => BrokerReportStatus::class,
            'to_status' => BrokerReportStatus::class,
            'report_snapshot' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Broker-report events are immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Broker-report events are immutable.');
        });
    }

    public function brokerReport(): BelongsTo
    {
        return $this->belongsTo(BrokerReport::class);
    }

    public function previousEvent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_event_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
