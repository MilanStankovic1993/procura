<?php

namespace App\Models;

use App\Enums\Risk\RiskAssessmentStatus;
use App\Enums\Risk\RiskConfidenceLevel;
use App\Enums\Risk\RiskLevel;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class RiskAssessment extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id',
        'analysis_id',
        'product_match_id',
        'comparable_set_id',
        'price_estimate_id',
        'run_number',
        'status',
        'evaluator_version',
        'input_hash',
        'assessment_key',
        'calculation_at',
        'score',
        'level',
        'confidence_basis_points',
        'confidence_level',
        'signal_count',
        'unknown_count',
        'reason_codes',
        'confidence_components',
        'verification_actions',
        'input_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'run_number' => 'integer',
            'status' => RiskAssessmentStatus::class,
            'calculation_at' => 'immutable_datetime',
            'score' => 'integer',
            'level' => RiskLevel::class,
            'confidence_basis_points' => 'integer',
            'confidence_level' => RiskConfidenceLevel::class,
            'signal_count' => 'integer',
            'unknown_count' => 'integer',
            'reason_codes' => 'array',
            'confidence_components' => 'array',
            'verification_actions' => 'array',
            'input_snapshot' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Risk-assessment evidence is immutable.');
        });

        static::deleting(static function (): never {
            throw new LogicException(
                'Risk-assessment evidence cannot be deleted individually.',
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

    public function comparableSet(): BelongsTo
    {
        return $this->belongsTo(ComparableSet::class);
    }

    public function priceEstimate(): BelongsTo
    {
        return $this->belongsTo(PriceEstimate::class);
    }

    public function signals(): HasMany
    {
        return $this->hasMany(RiskSignal::class)->orderBy('position');
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
