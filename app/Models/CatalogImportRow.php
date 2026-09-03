<?php

namespace App\Models;

use App\Enums\Catalog\CatalogImportRowStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogImportRow extends Model
{
    use HasUlids;

    protected $fillable = [
        'catalog_import_id',
        'row_number',
        'status',
        'product_category_id',
        'brand_id',
        'product_model_id',
        'product_variant_id',
        'raw_payload',
        'normalized_payload',
        'validation_errors',
        'row_hash',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'row_number' => 'integer',
            'status' => CatalogImportRowStatus::class,
            'raw_payload' => 'array',
            'normalized_payload' => 'array',
            'validation_errors' => 'array',
            'processed_at' => 'immutable_datetime',
        ];
    }

    public function catalogImport(): BelongsTo
    {
        return $this->belongsTo(CatalogImport::class);
    }

    public function productCategory(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function productModel(): BelongsTo
    {
        return $this->belongsTo(ProductModel::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
