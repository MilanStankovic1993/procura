<?php

namespace App\Models;

use App\Enums\Subscriptions\BillingInterval;
use App\Enums\Subscriptions\PlanCode;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingCheckoutSession extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id',
        'actor_user_id',
        'provider',
        'idempotency_hash',
        'plan_code',
        'billing_interval',
        'provider_session_id',
        'provider_customer_id',
        'provider_price_id',
        'checkout_url',
        'status',
        'expires_at',
    ];

    protected $hidden = [
        'idempotency_hash',
        'provider_customer_id',
        'provider_price_id',
        'checkout_url',
    ];

    protected function casts(): array
    {
        return [
            'plan_code' => PlanCode::class,
            'billing_interval' => BillingInterval::class,
            'checkout_url' => 'encrypted',
            'expires_at' => 'immutable_datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
