<?php

namespace App\Models;

use App\Enums\Sell\SellPriceBandItemDecision;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SellPriceBandItem extends Model
{
    use HasUlids;

    protected $fillable = [
        'sell_price_band_id',
        'sell_comparable_selection_item_id',
        'sell_comparable_record_id',
        'position',
        'decision',
        'asking_price_minor',
        'currency_code',
        'weight_basis_points',
        'reason_codes',
        'evidence_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'decision' => SellPriceBandItemDecision::class,
            'asking_price_minor' => 'integer',
            'weight_basis_points' => 'integer',
            'reason_codes' => 'array',
            'evidence_snapshot' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Sell price-band items are immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException(
                'Sell price-band items cannot be deleted individually.',
            );
        });
    }

    public function priceBand(): BelongsTo
    {
        return $this->belongsTo(SellPriceBand::class);
    }

    public function selectionItem(): BelongsTo
    {
        return $this->belongsTo(
            SellComparableSelectionItem::class,
            'sell_comparable_selection_item_id',
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
