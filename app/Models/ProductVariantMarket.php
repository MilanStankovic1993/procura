<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariantMarket extends Model
{
    use HasUlids;

    protected $fillable = [
        'product_variant_id',
        'country_code',
        'market_model_number',
        'voltage_millivolts',
        'plug_type',
        'measurement_system',
        'warranty_applicable',
        'included_accessories',
    ];

    protected function casts(): array
    {
        return [
            'voltage_millivolts' => 'integer',
            'warranty_applicable' => 'boolean',
            'included_accessories' => 'array',
        ];
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
