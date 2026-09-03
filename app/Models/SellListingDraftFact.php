<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SellListingDraftFact extends Model
{
    use HasUlids;

    protected $fillable = [
        'sell_listing_draft_id',
        'position',
        'fact_code',
        'source_kind',
        'source_id',
        'source_field',
        'is_unknown',
        'disclosure',
        'value_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_unknown' => 'boolean',
            'value_snapshot' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Sell listing draft facts are immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException(
                'Sell listing draft facts cannot be deleted individually.',
            );
        });
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(
            SellListingDraft::class,
            'sell_listing_draft_id',
        );
    }
}
