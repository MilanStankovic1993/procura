<?php

namespace App\Models;

use App\Enums\Sell\SellPriceStrategy;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class SalePortfolioEntry extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id',
        'owned_product_id',
        'sell_listing_draft_id',
        'created_by_user_id',
        'sequence',
        'listing_draft_input_hash',
        'assessment_input_hash',
        'price_band_input_hash',
        'image_evidence_hash',
        'entry_key',
        'payload_hash',
        'idempotency_key',
        'target_country_code',
        'target_currency_code',
        'listing_language',
        'price_strategy',
        'initial_asking_price_minor',
        'source_identifiers',
        'input_snapshot',
        'entered_at',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'price_strategy' => SellPriceStrategy::class,
            'initial_asking_price_minor' => 'integer',
            'source_identifiers' => 'array',
            'input_snapshot' => 'array',
            'entered_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Sale portfolio entries are immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException(
                'Sale portfolio entries cannot be deleted individually.',
            );
        });
    }

    public function ownedProduct(): BelongsTo
    {
        return $this->belongsTo(OwnedProduct::class);
    }

    public function listingDraft(): BelongsTo
    {
        return $this->belongsTo(
            SellListingDraft::class,
            'sell_listing_draft_id',
        );
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SalePortfolioEvent::class)
            ->orderByDesc('sequence');
    }

    public function currentEvent(): HasOne
    {
        return $this->hasOne(SalePortfolioEvent::class)
            ->ofMany('sequence', 'max');
    }

    public function actualSales(): HasMany
    {
        return $this->hasMany(ActualSale::class)
            ->orderByDesc('sequence');
    }

    public function currentActualSale(): HasOne
    {
        return $this->hasOne(ActualSale::class)
            ->ofMany('sequence', 'max');
    }
}
