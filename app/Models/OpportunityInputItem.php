<?php

namespace App\Models;

use App\Enums\Opportunity\OpportunityComponent;
use App\Enums\Opportunity\OpportunityEvidenceCode;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class OpportunityInputItem extends Model
{
    use HasUlids;

    protected $fillable = [
        'opportunity_input_id',
        'component',
        'position',
        'code',
        'value_type',
        'value_payload',
        'is_known',
        'is_required',
        'source',
        'evidence_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'component' => OpportunityComponent::class,
            'position' => 'integer',
            'code' => OpportunityEvidenceCode::class,
            'value_payload' => 'array',
            'is_known' => 'boolean',
            'is_required' => 'boolean',
            'evidence_snapshot' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Opportunity-input items are immutable.');
        });

        static::deleting(static function (): never {
            throw new LogicException(
                'Opportunity-input items cannot be deleted individually.',
            );
        });
    }

    public function opportunityInput(): BelongsTo
    {
        return $this->belongsTo(OpportunityInput::class);
    }

    public function assessmentItems(): HasMany
    {
        return $this->hasMany(OpportunityAssessmentItem::class);
    }

    public function value(): mixed
    {
        return $this->value_payload['value'] ?? null;
    }
}
