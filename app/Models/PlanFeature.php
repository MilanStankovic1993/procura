<?php

namespace App\Models;

use App\Enums\Subscriptions\FeatureCode;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanFeature extends Model
{
    use HasUlids;

    protected $fillable = ['plan_id', 'feature_code', 'is_enabled', 'limit'];

    protected function casts(): array
    {
        return ['feature_code' => FeatureCode::class, 'is_enabled' => 'boolean', 'limit' => 'integer'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
