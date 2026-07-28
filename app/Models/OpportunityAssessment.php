<?php

namespace App\Models;

use App\Enums\Opportunity\OpportunityAssessmentStatus;
use App\Enums\Opportunity\OpportunityComponent;
use App\Enums\Opportunity\OpportunityConfidenceLevel;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class OpportunityAssessment extends Model
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
        'opportunity_input_id',
        'component',
        'run_number',
        'status',
        'evaluator_version',
        'input_hash',
        'assessment_key',
        'calculated_at',
        'score',
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
            'component' => OpportunityComponent::class,
            'run_number' => 'integer',
            'status' => OpportunityAssessmentStatus::class,
            'calculated_at' => 'immutable_datetime',
            'score' => 'integer',
            'confidence_basis_points' => 'integer',
            'confidence_level' => OpportunityConfidenceLevel::class,
            'unknown_count' => 'integer',
            'reason_codes' => 'array',
            'confidence_components' => 'array',
            'input_snapshot' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Opportunity assessments are immutable.');
        });

        static::deleting(static function (): never {
            throw new LogicException(
                'Opportunity assessments cannot be deleted individually.',
            );
        });
    }

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }

    public function opportunityInput(): BelongsTo
    {
        return $this->belongsTo(OpportunityInput::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OpportunityAssessmentItem::class)
            ->orderBy('position');
    }
}
