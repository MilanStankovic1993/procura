<?php

namespace App\Models;

use App\Enums\Monitoring\SavedSearchMatchStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class SavedSearchMatch extends Model
{
    use BelongsToOrganization, HasUlids;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => SavedSearchMatchStatus::class,
            'reason_codes' => 'array',
            'unknown_criteria' => 'array',
            'evidence_snapshot' => 'array',
            'evaluated_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Saved-search matches are immutable.');
        });

        static::deleting(static function (): never {
            throw new LogicException(
                'Saved-search matches cannot be deleted individually.',
            );
        });
    }

    public function savedSearch(): BelongsTo
    {
        return $this->belongsTo(SavedSearch::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(
            SavedSearchVersion::class,
            'saved_search_version_id',
        );
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function listingSnapshot(): BelongsTo
    {
        return $this->belongsTo(ListingSnapshot::class);
    }

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }

    public function alert(): HasOne
    {
        return $this->hasOne(Alert::class, 'saved_search_match_id');
    }
}
