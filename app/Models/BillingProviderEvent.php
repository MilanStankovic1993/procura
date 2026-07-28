<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class BillingProviderEvent extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'provider',
        'provider_event_id',
        'organization_id',
        'event_type',
        'provider_customer_id',
        'provider_subscription_id',
        'provider_price_id',
        'provider_status',
        'payload_sha256',
        'livemode',
        'outcome',
        'reason_code',
        'projected_plan_code',
        'occurred_at',
        'processed_at',
    ];

    protected $hidden = [
        'provider_customer_id',
        'provider_subscription_id',
        'provider_price_id',
        'payload_sha256',
    ];

    protected function casts(): array
    {
        return [
            'livemode' => 'boolean',
            'occurred_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Billing provider events are immutable.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Billing provider events cannot be deleted.');
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
