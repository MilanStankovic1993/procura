<?php

namespace App\Models;

use App\Enums\Analyses\AiAnalysisStatus;
use App\Enums\Analyses\AnalysisPipelineProviderScope;
use App\Enums\Analyses\AnalysisPipelineStage;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class AnalysisPipelineMetric extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $fillable = [
        'ai_analysis_id',
        'attempt_number',
        'pipeline_version',
        'provider_scope',
        'attempt_status',
        'failed_stage',
        'provider_analysis_microseconds',
        'product_matching_microseconds',
        'comparable_selection_microseconds',
        'price_estimation_microseconds',
        'risk_assessment_microseconds',
        'finalization_microseconds',
        'total_microseconds',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'provider_scope' => AnalysisPipelineProviderScope::class,
            'attempt_status' => AiAnalysisStatus::class,
            'failed_stage' => AnalysisPipelineStage::class,
            'provider_analysis_microseconds' => 'integer',
            'product_matching_microseconds' => 'integer',
            'comparable_selection_microseconds' => 'integer',
            'price_estimation_microseconds' => 'integer',
            'risk_assessment_microseconds' => 'integer',
            'finalization_microseconds' => 'integer',
            'total_microseconds' => 'integer',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new LogicException('Analysis pipeline metrics are immutable.');
        });
        self::deleting(static function (): never {
            throw new LogicException(
                'Analysis pipeline metrics may only be removed by the retention purge.',
            );
        });
    }

    public function aiAnalysis(): BelongsTo
    {
        return $this->belongsTo(AiAnalysis::class);
    }
}
