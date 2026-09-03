<?php

namespace App\Models;

use App\Enums\DealScoring\DealScoreComponent;
use App\Enums\DealScoring\DealScoreImpact;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class DealScoreItem extends Model
{
    use HasUlids;

    protected $fillable = [
        'deal_score_id',
        'position',
        'component',
        'weight_basis_points',
        'raw_value',
        'raw_value_unit',
        'normalized_score_basis_points',
        'weighted_contribution_basis_points',
        'confidence_basis_points',
        'impact',
        'source_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'component' => DealScoreComponent::class,
            'weight_basis_points' => 'integer',
            'raw_value' => 'integer',
            'normalized_score_basis_points' => 'integer',
            'weighted_contribution_basis_points' => 'integer',
            'confidence_basis_points' => 'integer',
            'impact' => DealScoreImpact::class,
            'source_snapshot' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Deal-score items are immutable.');
        });

        static::deleting(static function (): never {
            throw new LogicException(
                'Deal-score items cannot be deleted individually.',
            );
        });
    }

    public function dealScore(): BelongsTo
    {
        return $this->belongsTo(DealScore::class);
    }
}
