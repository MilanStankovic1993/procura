<?php

namespace App\Models;

use App\Enums\Profit\ProfitConfidenceLevel;
use App\Enums\Profit\ProfitEstimateStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class ProfitEstimate extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id',
        'analysis_id',
        'price_estimate_id',
        'risk_assessment_id',
        'cost_input_id',
        'run_number',
        'status',
        'calculation_version',
        'input_hash',
        'estimate_key',
        'calculation_at',
        'currency_code',
        'expected_sale_price_minor',
        'purchase_price_minor',
        'gross_margin_minor',
        'known_costs_minor',
        'additional_costs_minor',
        'total_cost_minor',
        'expected_net_profit_minor',
        'profit_margin_basis_points',
        'return_on_invested_capital_basis_points',
        'confidence_basis_points',
        'confidence_level',
        'unknown_count',
        'reason_codes',
        'confidence_components',
        'input_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'run_number' => 'integer',
            'status' => ProfitEstimateStatus::class,
            'calculation_at' => 'immutable_datetime',
            'expected_sale_price_minor' => 'integer',
            'purchase_price_minor' => 'integer',
            'gross_margin_minor' => 'integer',
            'known_costs_minor' => 'integer',
            'additional_costs_minor' => 'integer',
            'total_cost_minor' => 'integer',
            'expected_net_profit_minor' => 'integer',
            'profit_margin_basis_points' => 'integer',
            'return_on_invested_capital_basis_points' => 'integer',
            'confidence_basis_points' => 'integer',
            'confidence_level' => ProfitConfidenceLevel::class,
            'unknown_count' => 'integer',
            'reason_codes' => 'array',
            'confidence_components' => 'array',
            'input_snapshot' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Profit-estimate evidence is immutable.');
        });

        static::deleting(static function (): never {
            throw new LogicException(
                'Profit-estimate evidence cannot be deleted individually.',
            );
        });
    }

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }

    public function priceEstimate(): BelongsTo
    {
        return $this->belongsTo(PriceEstimate::class);
    }

    public function riskAssessment(): BelongsTo
    {
        return $this->belongsTo(RiskAssessment::class);
    }

    public function costInput(): BelongsTo
    {
        return $this->belongsTo(CostInput::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_code');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProfitEstimateItem::class)->orderBy('position');
    }

    public function opportunityInputs(): HasMany
    {
        return $this->hasMany(OpportunityInput::class)
            ->orderByDesc('run_number');
    }

    public function opportunityAssessments(): HasMany
    {
        return $this->hasMany(OpportunityAssessment::class)
            ->orderByDesc('run_number');
    }

    public function dealScores(): HasMany
    {
        return $this->hasMany(DealScore::class)->orderByDesc('run_number');
    }

    public function outcomeEstimateAttributions(): HasMany
    {
        return $this->hasMany(OutcomeEstimateAttribution::class);
    }

    public function accuracyReports(): HasMany
    {
        return $this->hasMany(EstimateAccuracyReport::class);
    }
}
