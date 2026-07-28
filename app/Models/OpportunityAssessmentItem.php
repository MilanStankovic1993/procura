<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class OpportunityAssessmentItem extends Model
{
    use HasUlids;

    protected $fillable = [
        'opportunity_assessment_id',
        'opportunity_input_item_id',
        'position',
        'code',
        'maximum_points',
        'score_contribution',
        'is_known',
        'source_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'maximum_points' => 'integer',
            'score_contribution' => 'integer',
            'is_known' => 'boolean',
            'source_snapshot' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Opportunity-assessment items are immutable.');
        });

        static::deleting(static function (): never {
            throw new LogicException(
                'Opportunity-assessment items cannot be deleted individually.',
            );
        });
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(OpportunityAssessment::class);
    }

    public function inputItem(): BelongsTo
    {
        return $this->belongsTo(
            OpportunityInputItem::class,
            'opportunity_input_item_id',
        );
    }
}
