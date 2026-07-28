<?php

namespace App\Models;

use App\Enums\Subscriptions\FeatureCode;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionUsage extends Model
{
    use HasUlids;

    protected $fillable = [
        'organization_id', 'feature_code', 'period_start', 'period_end', 'used',
    ];

    protected function casts(): array
    {
        return [
            'feature_code' => FeatureCode::class,
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'used' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
