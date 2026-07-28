<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class OpportunityInput extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id',
        'analysis_id',
        'comparable_set_id',
        'price_estimate_id',
        'risk_assessment_id',
        'cost_input_id',
        'profit_estimate_id',
        'submitted_by_user_id',
        'run_number',
        'input_version',
        'input_hash',
        'input_key',
        'source_country_code',
        'target_country_code',
        'known_count',
        'unknown_count',
        'input_snapshot',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'run_number' => 'integer',
            'known_count' => 'integer',
            'unknown_count' => 'integer',
            'input_snapshot' => 'array',
            'submitted_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Opportunity-input evidence is immutable.');
        });

        static::deleting(static function (): never {
            throw new LogicException(
                'Opportunity-input evidence cannot be deleted individually.',
            );
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

    public function profitEstimate(): BelongsTo
    {
        return $this->belongsTo(ProfitEstimate::class);
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OpportunityInputItem::class)
            ->orderBy('component')
            ->orderBy('position');
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(OpportunityAssessment::class)
            ->orderBy('component');
    }
}
