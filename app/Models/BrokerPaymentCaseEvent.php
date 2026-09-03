<?php

namespace App\Models;

use App\Enums\BrokerRequests\BrokerPaymentCaseEventType;
use App\Enums\BrokerRequests\BrokerPaymentCaseOutcome;
use App\Enums\BrokerRequests\BrokerPaymentCaseStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class BrokerPaymentCaseEvent extends Model
{
    use BelongsToOrganization, HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id',
        'broker_request_id',
        'broker_request_offer_id',
        'broker_transaction_id',
        'broker_payment_case_id',
        'previous_event_id',
        'actor_user_id',
        'sequence',
        'event_type',
        'from_status',
        'to_status',
        'resolution_outcome',
        'reason_code',
        'evidence_reference',
        'idempotency_key',
        'payload_hash',
        'payment_case_hash',
        'payment_case_snapshot',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'event_type' => BrokerPaymentCaseEventType::class,
            'from_status' => BrokerPaymentCaseStatus::class,
            'to_status' => BrokerPaymentCaseStatus::class,
            'resolution_outcome' => BrokerPaymentCaseOutcome::class,
            'payment_case_snapshot' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Broker payment-case events are immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Broker payment-case events are immutable.');
        });
    }

    public function brokerPaymentCase(): BelongsTo
    {
        return $this->belongsTo(BrokerPaymentCase::class);
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
