<?php

namespace App\Models;

use App\Enums\Comparables\ComparableDecision;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SellComparableSelectionItem extends Model
{
    use HasUlids;

    protected $fillable = [
        'sell_comparable_selection_id',
        'sell_comparable_record_id',
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
            throw new LogicException('Sell comparable selection items are immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException(
                'Sell comparable selection items cannot be deleted individually.',
            );
        });
    }

    public function selection(): BelongsTo
    {
        return $this->belongsTo(
            SellComparableSelection::class,
            'sell_comparable_selection_id',
        );
    }

    public function comparableRecord(): BelongsTo
    {
        return $this->belongsTo(
            SellComparableRecord::class,
            'sell_comparable_record_id',
        );
    }
}
