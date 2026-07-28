<?php

namespace App\Models;

use App\Enums\Comparables\ComparableCondition;
use App\Enums\Comparables\ComparableListingType;
use App\Enums\Comparables\ComparableSellerType;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class ComparableRecord extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id',
        'marketplace_source_id',
        'created_by_user_id',
        'product_model_id',
        'product_variant_id',
        'source_identity_hash',
        'evidence_hash',
        'record_key',
        'marketplace_name',
        'marketplace_key',
        'source_url',
        'external_id',
        'title',
        'description',
        'listing_type',
        'condition_code',
        'seller_type',
        'asking_price_minor',
        'currency_code',
        'country_code',
        'location',
        'included_accessories',
        'missing_accessories',
        'source_reliability_basis_points',
        'published_at',
        'observed_at',
        'raw_input',
    ];

    protected function casts(): array
    {
        return [
            'listing_type' => ComparableListingType::class,
            'condition_code' => ComparableCondition::class,
            'seller_type' => ComparableSellerType::class,
            'asking_price_minor' => 'integer',
            'included_accessories' => 'array',
            'missing_accessories' => 'array',
            'source_reliability_basis_points' => 'integer',
            'published_at' => 'immutable_datetime',
            'observed_at' => 'immutable_datetime',
            'raw_input' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Comparable source evidence is immutable.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Comparable source evidence cannot be deleted individually.');
        });
    }

    public function marketplaceSource(): BelongsTo
    {
        return $this->belongsTo(MarketplaceSource::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function productModel(): BelongsTo
    {
        return $this->belongsTo(ProductModel::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function setItems(): HasMany
    {
        return $this->hasMany(ComparableSetItem::class);
    }

    public function marketNormalizations(): HasMany
    {
        return $this->hasMany(ComparableMarketNormalization::class)
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }
}
