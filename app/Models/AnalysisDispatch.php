<?php

namespace App\Models;

use App\Enums\Analyses\AnalysisDispatchStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class AnalysisDispatch extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id',
        'analysis_id',
        'run_number',
        'pipeline_version',
        'dispatch_key',
        'status',
        'queue_name',
        'dispatch_attempts',
        'max_processing_attempts',
        'available_at',
        'last_dispatch_attempt_at',
        'dispatched_at',
        'completed_at',
        'failed_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'status' => AnalysisDispatchStatus::class,
            'run_number' => 'integer',
            'dispatch_attempts' => 'integer',
            'max_processing_attempts' => 'integer',
            'available_at' => 'immutable_datetime',
            'last_dispatch_attempt_at' => 'immutable_datetime',
            'dispatched_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(static function (): never {
            throw new LogicException(
                'Analysis dispatches cannot be deleted individually.',
            );
        });
    }

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }

    public function retryEventsFrom(): HasMany
    {
        return $this->hasMany(
            AnalysisRetryEvent::class,
            'previous_dispatch_id',
        );
    }

    public function retryEventsTo(): HasMany
    {
        return $this->hasMany(
            AnalysisRetryEvent::class,
            'new_dispatch_id',
        );
    }
}
