<?php

namespace App\Models;

use App\Enums\Analyses\AnalysisProviderUsageStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class AnalysisProviderUsage extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_date',
            'status' => AnalysisProviderUsageStatus::class,
            'reserved_cost_minor' => 'integer',
            'actual_cost_minor' => 'integer',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (self $usage): void {
            if ($usage->isDirty([
                'organization_id',
                'user_id',
                'analysis_id',
                'ai_analysis_id',
                'provider',
                'model',
                'period_start',
                'reserved_cost_minor',
                'cost_currency',
                'started_at',
            ])) {
                throw new LogicException('Analysis provider usage identity is immutable.');
            }

            if (
                $usage->getRawOriginal('status')
                !== AnalysisProviderUsageStatus::Reserved->value
                || ! in_array($usage->status, [
                    AnalysisProviderUsageStatus::Completed,
                    AnalysisProviderUsageStatus::Uncertain,
                ], true)
            ) {
                throw new LogicException('Analysis provider usage may settle exactly once.');
            }
        });

        self::deleting(function (): never {
            throw new LogicException('Analysis provider usage cannot be deleted individually.');
        });
    }

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }

    public function aiAnalysis(): BelongsTo
    {
        return $this->belongsTo(AiAnalysis::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
