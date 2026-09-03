<?php

namespace App\Models;

use App\Enums\BrokerRequests\BrokerRequestOfferEventType;
use App\Enums\BrokerRequests\BrokerRequestOfferStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class BrokerRequestOfferEvent extends Model
{
    use BelongsToOrganization, HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id',
        'broker_request_id',
        'broker_request_offer_id',
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
        'offer_hash',
        'offer_snapshot',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'event_type' => BrokerRequestOfferEventType::class,
            'from_status' => BrokerRequestOfferStatus::class,
            'to_status' => BrokerRequestOfferStatus::class,
            'offer_snapshot' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Broker-offer events are immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Broker-offer events are immutable.');
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

    public function previousEvent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_event_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
