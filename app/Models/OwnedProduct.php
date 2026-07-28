<?php

namespace App\Models;

use App\Enums\OwnedProducts\CrossBorderPreference;
use App\Enums\OwnedProducts\DesiredSaleSpeed;
use App\Enums\OwnedProducts\OwnedProductCondition;
use App\Enums\OwnedProducts\OwnedProductStatus;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\OwnedProductFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OwnedProduct extends Model
{
    /** @use HasFactory<OwnedProductFactory> */
    use BelongsToOrganization, HasFactory, HasUlids;

    protected $fillable = [
        'organization_id',
        'created_by_user_id',
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
        'cross_border_preference',
        'desired_sale_speed',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'condition' => OwnedProductCondition::class,
            'age_months' => 'integer',
            'accessories' => 'array',
            'defects' => 'array',
            'purchase_history_known' => 'boolean',
            'cross_border_preference' => CrossBorderPreference::class,
            'desired_sale_speed' => DesiredSaleSpeed::class,
            'status' => OwnedProductStatus::class,
            'raw_input' => 'array',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    public function targetContinent(): BelongsTo
    {
        return $this->belongsTo(Continent::class, 'target_continent_code');
    }

    public function targetCountries(): BelongsToMany
    {
        return $this->belongsToMany(
            Country::class,
            'owned_product_target_countries',
            'owned_product_id',
            'country_code',
        )
            ->withPivot('sort_order')
            ->orderByPivot('sort_order');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(OwnedProductSnapshot::class)->orderByDesc('sequence');
    }

    public function images(): HasMany
    {
        return $this->hasMany(OwnedProductImage::class)
            ->orderBy('kind')
            ->orderBy('position');
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(OwnedProductAssessment::class)
            ->orderByDesc('run_number');
    }

    public function sellComparableRecords(): HasMany
    {
        return $this->hasMany(SellComparableRecord::class)
            ->orderByDesc('observed_at');
    }

    public function sellComparableSelections(): HasMany
    {
        return $this->hasMany(SellComparableSelection::class)
            ->orderByDesc('run_number');
    }

    public function sellPriceBands(): HasMany
    {
        return $this->hasMany(SellPriceBand::class)
            ->orderByDesc('run_number');
    }

    public function sellComparableMarketNormalizations(): HasMany
    {
        return $this->hasMany(SellComparableMarketNormalization::class)
            ->orderByDesc('observed_at');
    }

    public function sellListingDrafts(): HasMany
    {
        return $this->hasMany(SellListingDraft::class)
            ->orderByDesc('run_number');
    }

    public function salePortfolioEntries(): HasMany
    {
        return $this->hasMany(SalePortfolioEntry::class)
            ->orderByDesc('sequence');
    }

    public function actualPurchases(): HasMany
    {
        return $this->hasMany(ActualPurchase::class)
            ->orderByDesc('sequence');
    }

    public function actualCostSnapshots(): HasMany
    {
        return $this->hasMany(ActualCostSnapshot::class)
            ->orderByDesc('sequence');
    }

    public function actualSales(): HasMany
    {
        return $this->hasMany(ActualSale::class)
            ->orderByDesc('created_at');
    }

    public function realizedProfits(): HasMany
    {
        return $this->hasMany(RealizedProfit::class)
            ->orderByDesc('run_number');
    }

    public function outcomeEstimateAttributions(): HasMany
    {
        return $this->hasMany(OutcomeEstimateAttribution::class)
            ->orderByDesc('sequence');
    }

    public function estimateAccuracyReports(): HasMany
    {
        return $this->hasMany(EstimateAccuracyReport::class)
            ->orderByDesc('sequence');
    }
}
