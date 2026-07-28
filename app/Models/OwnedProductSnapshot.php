<?php

namespace App\Models;

use App\Enums\OwnedProducts\CrossBorderPreference;
use App\Enums\OwnedProducts\DesiredSaleSpeed;
use App\Enums\OwnedProducts\OwnedProductCondition;
use App\Enums\OwnedProducts\OwnedProductStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class OwnedProductSnapshot extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $fillable = [
        'owned_product_id',
        'sequence',
        'captured_by_user_id',
        'captured_at',
        'product_category_id',
        'brand_name',
        'model_name',
        'condition',
        'age_months',
        'accessories',
        'defects',
        'purchase_history_known',
        'purchase_history',
        'target_continent_code',
        'target_country_codes',
        'cross_border_preference',
        'desired_sale_speed',
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
            'condition' => OwnedProductCondition::class,
            'age_months' => 'integer',
            'accessories' => 'array',
            'defects' => 'array',
            'purchase_history_known' => 'boolean',
            'target_country_codes' => 'array',
            'cross_border_preference' => CrossBorderPreference::class,
            'desired_sale_speed' => DesiredSaleSpeed::class,
            'status' => OwnedProductStatus::class,
            'raw_payload' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Owned-product snapshots are immutable.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Owned-product snapshots cannot be deleted individually.');
        });
    }

    public function ownedProduct(): BelongsTo
    {
        return $this->belongsTo(OwnedProduct::class);
    }

    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by_user_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(
            OwnedProductAssessment::class,
            'owned_product_snapshot_id',
        )->orderByDesc('run_number');
    }
}
