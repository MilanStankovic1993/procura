<?php

namespace App\Models;

use App\Enums\Pricing\PriceConfidenceLevel;
use App\Enums\Sell\SellPriceBandStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class SellPriceBand extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id',
        'owned_product_id',
        'owned_product_assessment_id',
        'sell_comparable_selection_id',
        'run_number',
        'status',
        'algorithm_version',
        'input_hash',
        'price_band_key',
        'calculated_at',
        'target_country_code',
        'target_currency_code',
        'input_count',
        'included_count',
        'outlier_count',
        'quick_sale_low_minor',
        'quick_sale_high_minor',
        'recommended_low_minor',
        'recommended_high_minor',
        'ambitious_low_minor',
        'ambitious_high_minor',
        'median_minor',
        'weighted_median_minor',
        'q1_minor',
        'q3_minor',
        'mad_minor',
        'dispersion_basis_points',
        'confidence_basis_points',
        'confidence_level',
        'completeness_basis_points',
        'confidence_components',
        'reason_codes',
        'unknown_facts',
        'verification_actions',
        'input_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'run_number' => 'integer',
            'status' => SellPriceBandStatus::class,
            'calculated_at' => 'immutable_datetime',
            'input_count' => 'integer',
            'included_count' => 'integer',
            'outlier_count' => 'integer',
            'quick_sale_low_minor' => 'integer',
            'quick_sale_high_minor' => 'integer',
            'recommended_low_minor' => 'integer',
            'recommended_high_minor' => 'integer',
            'ambitious_low_minor' => 'integer',
            'ambitious_high_minor' => 'integer',
            'median_minor' => 'integer',
            'weighted_median_minor' => 'integer',
            'q1_minor' => 'integer',
            'q3_minor' => 'integer',
            'mad_minor' => 'integer',
            'dispersion_basis_points' => 'integer',
            'confidence_basis_points' => 'integer',
            'confidence_level' => PriceConfidenceLevel::class,
            'completeness_basis_points' => 'integer',
            'confidence_components' => 'array',
            'reason_codes' => 'array',
            'unknown_facts' => 'array',
            'verification_actions' => 'array',
            'input_snapshot' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Sell price bands are immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException(
                'Sell price bands cannot be deleted individually.',
            );
        });
    }

    public function ownedProduct(): BelongsTo
    {
        return $this->belongsTo(OwnedProduct::class);
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(
            OwnedProductAssessment::class,
            'owned_product_assessment_id',
        );
    }

    public function selection(): BelongsTo
    {
        return $this->belongsTo(
            SellComparableSelection::class,
            'sell_comparable_selection_id',
        );
    }

    public function targetCountry(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'target_country_code');
    }

    public function targetCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'target_currency_code');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SellPriceBandItem::class)->orderBy('position');
    }

    public function listingDrafts(): HasMany
    {
        return $this->hasMany(SellListingDraft::class)
            ->orderByDesc('run_number');
    }
}
