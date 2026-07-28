<?php

namespace App\Models;

use App\Enums\Markets\ContinentCode;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class SavedSearchVersion extends Model
{
    use BelongsToOrganization, HasUlids;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'active' => 'boolean',
            'minimum_price_minor' => 'integer',
            'maximum_price_minor' => 'integer',
            'continent_code' => ContinentCode::class,
            'country_codes' => 'array',
            'radius_km' => 'integer',
            'include_cross_border' => 'boolean',
            'required_keywords' => 'array',
            'excluded_keywords' => 'array',
            'minimum_profit_minor' => 'integer',
            'minimum_margin_basis_points' => 'integer',
            'minimum_deal_score_basis_points' => 'integer',
            'maximum_risk_score' => 'integer',
            'notification_channels' => 'array',
            'criteria_snapshot' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Saved-search versions are immutable.');
        });

        static::deleting(static function (): never {
            throw new LogicException(
                'Saved-search versions cannot be deleted individually.',
            );
        });
    }

    public function savedSearch(): BelongsTo
    {
        return $this->belongsTo(SavedSearch::class);
    }

    public function previousVersion(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_version_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function productModel(): BelongsTo
    {
        return $this->belongsTo(ProductModel::class);
    }

    public function matches(): HasMany
    {
        return $this->hasMany(SavedSearchMatch::class);
    }
}
