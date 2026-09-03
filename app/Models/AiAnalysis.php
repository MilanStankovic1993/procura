<?php

namespace App\Models;

use App\Enums\Analyses\AiAnalysisStatus;
use App\Enums\Analyses\AiValidationStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AiAnalysis extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id',
        'analysis_id',
        'attempt_number',
        'status',
        'provider',
        'model',
        'prompt_version',
        'input_hash',
        'input_snapshot',
        'result_json',
        'validation_status',
        'confidence_basis_points',
        'tokens_in',
        'tokens_out',
        'estimated_cost_minor',
        'estimated_cost_currency',
        'started_at',
        'completed_at',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'status' => AiAnalysisStatus::class,
            'validation_status' => AiValidationStatus::class,
            'input_snapshot' => 'array',
            'result_json' => 'array',
            'confidence_basis_points' => 'integer',
            'tokens_in' => 'integer',
            'tokens_out' => 'integer',
            'estimated_cost_minor' => 'integer',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }

    public function productMatches(): HasMany
    {
        return $this->hasMany(ProductMatch::class);
    }

    public function pipelineMetric(): HasOne
    {
        return $this->hasOne(AnalysisPipelineMetric::class);
    }
}
