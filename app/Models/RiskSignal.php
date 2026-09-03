<?php

namespace App\Models;

use App\Enums\Risk\RiskCategory;
use App\Enums\Risk\RiskSeverity;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class RiskSignal extends Model
{
    use HasUlids;

    protected $fillable = [
        'risk_assessment_id',
        'position',
        'code',
        'category',
        'severity',
        'is_unknown',
        'weight_points',
        'score_contribution',
        'evidence_snapshot',
        'source',
        'confidence_basis_points',
        'verification_action',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'category' => RiskCategory::class,
            'severity' => RiskSeverity::class,
            'is_unknown' => 'boolean',
            'weight_points' => 'integer',
            'score_contribution' => 'integer',
            'evidence_snapshot' => 'array',
            'confidence_basis_points' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Risk signals are immutable.');
        });

        static::deleting(static function (): never {
            throw new LogicException(
                'Risk signals cannot be deleted individually.',
            );
        });
    }

    public function riskAssessment(): BelongsTo
    {
        return $this->belongsTo(RiskAssessment::class);
    }
}
