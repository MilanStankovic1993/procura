<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationPlanAssignment extends Model
{
    protected $primaryKey = 'organization_id';

    public $incrementing = false;

    protected $fillable = [
        'organization_id',
        'plan_id',
        'source',
        'provider',
        'provider_subscription_id',
        'provider_price_id',
        'provider_status',
        'provider_event_id',
        'provider_synced_at',
        'starts_at',
        'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'provider_synced_at' => 'immutable_datetime',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
