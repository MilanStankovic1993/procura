<?php

namespace App\Models;

use App\Enums\Subscriptions\PlanCode;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasUlids;

    protected $fillable = ['code', 'version', 'name', 'description', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['code' => PlanCode::class, 'is_active' => 'boolean'];
    }

    public function features(): HasMany
    {
        return $this->hasMany(PlanFeature::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(OrganizationPlanAssignment::class);
    }
}
