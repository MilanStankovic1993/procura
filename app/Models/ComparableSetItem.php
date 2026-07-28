<?php

namespace App\Models;

use App\Enums\Comparables\ComparableDecision;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class ComparableSetItem extends Model
{
    use HasUlids;

    protected $fillable = [
        'comparable_set_id',
        'comparable_record_id',
        'decision',
        'rank',
        'score_basis_points',
        'factor_scores',
        'reason_codes',
        'evidence_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'decision' => ComparableDecision::class,
            'rank' => 'integer',
            'score_basis_points' => 'integer',
            'factor_scores' => 'array',
            'reason_codes' => 'array',
            'evidence_snapshot' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Comparable set items are immutable.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Comparable set items cannot be deleted individually.');
        });
    }

    public function comparableSet(): BelongsTo
    {
        return $this->belongsTo(ComparableSet::class);
    }

    public function comparableRecord(): BelongsTo
    {
        return $this->belongsTo(ComparableRecord::class);
    }

    public function priceEstimateItems(): HasMany
    {
        return $this->hasMany(PriceEstimateItem::class);
    }
}
