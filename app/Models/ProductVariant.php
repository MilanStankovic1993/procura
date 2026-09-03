<?php

namespace App\Models;

use App\Catalog\CatalogTextNormalizer;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariant extends Model
{
    use HasUlids;

    protected $fillable = [
        'product_model_id',
        'name',
        'canonical_key',
        'sku',
        'normalized_sku',
        'attributes',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'attributes' => 'array',
            'active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ProductVariant $variant): void {
            $variant->normalized_sku = $variant->sku === null
                ? null
                : CatalogTextNormalizer::normalize($variant->sku);
            $variant->normalized_sku = $variant->normalized_sku === ''
                ? null
                : $variant->normalized_sku;
        });
    }

    public function productModel(): BelongsTo
    {
        return $this->belongsTo(ProductModel::class);
    }

    public function marketContexts(): HasMany
    {
        return $this->hasMany(ProductVariantMarket::class);
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(ProductAlias::class);
    }

    public function comparableRecords(): HasMany
    {
        return $this->hasMany(ComparableRecord::class);
    }
}
