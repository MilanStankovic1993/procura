<?php

namespace App\Models;

use App\Catalog\CatalogTextNormalizer;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ProductAlias extends Model
{
    use HasUlids;

    protected $fillable = [
        'product_model_id',
        'product_variant_id',
        'alias',
        'normalized_alias',
        'locale',
        'country_code',
        'source',
        'alias_key',
        'active',
    ];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(function (ProductAlias $alias): void {
            $alias->source ??= 'catalog';
            $alias->normalized_alias = CatalogTextNormalizer::normalize($alias->alias);

            if ($alias->normalized_alias === '') {
                throw new LogicException('A product alias must contain searchable characters.');
            }

            $alias->alias_key = hash('sha256', implode('|', [
                $alias->product_model_id,
                $alias->product_variant_id ?? 'model',
                $alias->normalized_alias,
                $alias->locale ?? 'any-locale',
                $alias->country_code ?? 'global',
                $alias->source,
            ]));
        });

        static::updating(function (ProductAlias $alias): void {
            if ($alias->isDirty([
                'product_model_id',
                'product_variant_id',
                'alias',
                'normalized_alias',
                'locale',
                'country_code',
                'source',
                'alias_key',
            ])) {
                throw new LogicException('Product alias identity is immutable after creation.');
            }
        });
    }

    public function productModel(): BelongsTo
    {
        return $this->belongsTo(ProductModel::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_code');
    }
}
