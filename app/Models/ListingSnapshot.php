<?php

namespace App\Models;

use App\Enums\Listings\ListingStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ListingSnapshot extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $fillable = [
        'listing_id',
        'sequence',
        'captured_by_user_id',
        'captured_at',
        'source_url',
        'external_id',
        'marketplace_name',
        'marketplace_key',
        'title',
        'description',
        'asking_price_minor',
        'currency_code',
        'seller_information',
        'location',
        'source_country_code',
        'target_country_code',
        'status',
        'notes',
        'raw_payload',
        'content_hash',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'captured_at' => 'immutable_datetime',
            'asking_price_minor' => 'integer',
            'status' => ListingStatus::class,
            'raw_payload' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Listing snapshots are immutable.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Listing snapshots cannot be deleted individually.');
        });
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by_user_id');
    }
}
