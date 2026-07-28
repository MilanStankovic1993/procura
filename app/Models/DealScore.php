<?php

namespace App\Models;

use App\Enums\DealScoring\DealRecommendation;
use App\Enums\DealScoring\DealScoreConfidenceLevel;
use App\Enums\DealScoring\DealScoreStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class DealScore extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id',
        'analysis_id',
        'product_match_id',
        'price_estimate_id',
        'risk_assessment_id',
        'profit_estimate_id',
        'opportunity_input_id',
        'logistics_assessment_id',
        'demand_assessment_id',
        'run_number',
        'status',
        'calculation_version',
        'input_hash',
        'score_key',
        'calculated_at',
        'uncapped_score',
        'uncapped_score_basis_points',
        'score',
        'score_basis_points',
        'recommendation',
        'confidence_basis_points',
        'confidence_level',
        'unknown_count',
        'applicable_cap',
        'cap_decisions',
        'reason_codes',
        'confidence_components',
        'factors_increasing',
        'factors_reducing',
        'assumptions',
        'verification_actions',
        'input_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'run_number' => 'integer',
            'status' => DealScoreStatus::class,
            'calculated_at' => 'immutable_datetime',
            'uncapped_score' => 'integer',
            'uncapped_score_basis_points' => 'integer',
            'score' => 'integer',
            'score_basis_points' => 'integer',
            'recommendation' => DealRecommendation::class,
            'confidence_basis_points' => 'integer',
            'confidence_level' => DealScoreConfidenceLevel::class,
            'unknown_count' => 'integer',
            'applicable_cap' => 'integer',
            'cap_decisions' => 'array',
            'reason_codes' => 'array',
            'confidence_components' => 'array',
            'factors_increasing' => 'array',
            'factors_reducing' => 'array',
            'assumptions' => 'array',
            'verification_actions' => 'array',
            'input_snapshot' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Deal scores are immutable.');
        });

        static::deleting(static function (): never {
            throw new LogicException(
                'Deal scores cannot be deleted individually.',
            );
        });
    }

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }

    public function productMatch(): BelongsTo
    {
        return $this->belongsTo(ProductMatch::class);
    }

    public function priceEstimate(): BelongsTo
    {
        return $this->belongsTo(PriceEstimate::class);
    }

    public function riskAssessment(): BelongsTo
    {
        return $this->belongsTo(RiskAssessment::class);
    }

    public function profitEstimate(): BelongsTo
    {
        return $this->belongsTo(ProfitEstimate::class);
    }

    public function opportunityInput(): BelongsTo
    {
        return $this->belongsTo(OpportunityInput::class);
    }

    public function logisticsAssessment(): BelongsTo
    {
        return $this->belongsTo(
            OpportunityAssessment::class,
            'logistics_assessment_id',
        );
    }

    public function demandAssessment(): BelongsTo
    {
        return $this->belongsTo(
            OpportunityAssessment::class,
            'demand_assessment_id',
        );
    }

    public function items(): HasMany
    {
        return $this->hasMany(DealScoreItem::class)->orderBy('position');
    }

    public function buyerDecisionEvents(): HasMany
    {
        return $this->hasMany(BuyerDecisionEvent::class)
            ->orderByDesc('sequence');
    }
}
