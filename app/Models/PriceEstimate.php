<?php

namespace App\Models;

use App\Enums\Pricing\PriceConfidenceLevel;
use App\Enums\Pricing\PriceEstimateStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class PriceEstimate extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id',
        'analysis_id',
        'comparable_set_id',
        'run_number',
        'status',
        'algorithm_version',
        'rate_resolver_version',
        'input_hash',
        'estimate_key',
        'calculation_at',
        'target_country_code',
        'target_currency_code',
        'input_count',
        'included_count',
        'outlier_count',
        'unresolved_count',
        'estimate_low_minor',
        'estimate_minor',
        'estimate_high_minor',
        'median_minor',
        'weighted_median_minor',
        'q1_minor',
        'q3_minor',
        'mad_minor',
        'dispersion_basis_points',
        'confidence_basis_points',
        'confidence_level',
        'reason_codes',
        'confidence_components',
        'input_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'run_number' => 'integer',
            'status' => PriceEstimateStatus::class,
            'calculation_at' => 'immutable_datetime',
            'input_count' => 'integer',
            'included_count' => 'integer',
            'outlier_count' => 'integer',
            'unresolved_count' => 'integer',
            'estimate_low_minor' => 'integer',
            'estimate_minor' => 'integer',
            'estimate_high_minor' => 'integer',
            'median_minor' => 'integer',
            'weighted_median_minor' => 'integer',
            'q1_minor' => 'integer',
            'q3_minor' => 'integer',
            'mad_minor' => 'integer',
            'dispersion_basis_points' => 'integer',
            'confidence_basis_points' => 'integer',
            'confidence_level' => PriceConfidenceLevel::class,
            'reason_codes' => 'array',
            'confidence_components' => 'array',
            'input_snapshot' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Price-estimate evidence is immutable.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Price-estimate evidence cannot be deleted individually.');
        });
    }

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }

    public function comparableSet(): BelongsTo
    {
        return $this->belongsTo(ComparableSet::class);
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
        return $this->hasMany(PriceEstimateItem::class)->orderBy('position');
    }

    public function riskAssessments(): HasMany
    {
        return $this->hasMany(RiskAssessment::class)->orderByDesc('run_number');
    }

    public function costInputs(): HasMany
    {
        return $this->hasMany(CostInput::class)->orderByDesc('run_number');
    }

    public function profitEstimates(): HasMany
    {
        return $this->hasMany(ProfitEstimate::class)->orderByDesc('run_number');
    }
}
