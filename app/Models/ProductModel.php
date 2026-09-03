<?php

namespace App\Models;

use App\Catalog\CatalogTextNormalizer;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class ProductModel extends Model
{
    use HasUlids;

    protected $fillable = [
        'brand_id',
        'product_category_id',
        'name',
        'normalized_name',
        'model_number',
        'normalized_model_number',
        'canonical_key',
        'specifications',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'specifications' => 'array',
            'active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ProductModel $model): void {
            $model->normalized_name = CatalogTextNormalizer::normalize($model->name);
            $model->normalized_model_number = CatalogTextNormalizer::normalize(
                $model->model_number,
            );

            if ($model->normalized_name === '' || $model->normalized_model_number === '') {
                throw new LogicException(
                    'A product model name and model number must contain searchable characters.',
                );
            }
        });
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
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
