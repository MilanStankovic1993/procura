<?php

namespace App\Models;

use App\Catalog\CatalogTextNormalizer;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class Brand extends Model
{
    use HasUlids;

    protected $fillable = [
        'name',
        'normalized_name',
        'active',
    ];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (Brand $brand): void {
            $brand->normalized_name = CatalogTextNormalizer::normalize($brand->name);

            if ($brand->normalized_name === '') {
                throw new LogicException('A brand name must contain searchable characters.');
            }
        });
    }

    public function productModels(): HasMany
    {
        return $this->hasMany(ProductModel::class);
    }
}
