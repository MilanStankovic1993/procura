<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class SavedSearch extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id',
        'owner_user_id',
        'title',
        'active',
        'current_version_id',
        'version_sequence',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'version_sequence' => 'integer',
            'archived_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (SavedSearch $search): void {
            if ($search->isDirty(['organization_id', 'owner_user_id'])) {
                throw new LogicException(
                    'Saved-search ownership is immutable after creation.',
                );
            }
        });
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(SavedSearchVersion::class, 'current_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(SavedSearchVersion::class)
            ->orderByDesc('sequence');
    }

    public function matches(): HasMany
    {
        return $this->hasMany(SavedSearchMatch::class)
            ->orderByDesc('evaluated_at');
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class)->orderByDesc('triggered_at');
    }
}
