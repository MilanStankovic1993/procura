<?php

namespace App\Models;

use App\Enums\OwnedProducts\OwnedProductImageKind;
use App\Enums\Sell\SellPhotoCheckStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SellListingPhotoCheckItem extends Model
{
    use HasUlids;

    protected $fillable = [
        'sell_listing_draft_id',
        'position',
        'check_code',
        'status',
        'required',
        'image_kind',
        'minimum_count',
        'observed_count',
        'matching_image_ids',
        'reason_codes',
        'verification_actions',
        'evidence_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'status' => SellPhotoCheckStatus::class,
            'required' => 'boolean',
            'image_kind' => OwnedProductImageKind::class,
            'minimum_count' => 'integer',
            'observed_count' => 'integer',
            'matching_image_ids' => 'array',
            'reason_codes' => 'array',
            'verification_actions' => 'array',
            'evidence_snapshot' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException(
                'Sell listing photo checklist items are immutable.',
            );
        });
        static::deleting(static function (): never {
            throw new LogicException(
                'Sell listing photo checklist items cannot be deleted individually.',
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
