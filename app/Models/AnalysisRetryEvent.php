<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class AnalysisRetryEvent extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id',
        'analysis_id',
        'previous_dispatch_id',
        'new_dispatch_id',
        'actor_user_id',
        'run_number',
        'previous_processing_attempts',
        'previous_failed_at',
        'previous_error_code',
        'previous_error_hash',
        'reason',
        'idempotency_key',
        'payload_hash',
        'requested_at',
    ];

    protected $hidden = [
        'idempotency_key',
        'payload_hash',
        'previous_error_hash',
    ];

    protected function casts(): array
    {
        return [
            'run_number' => 'integer',
            'previous_processing_attempts' => 'integer',
            'previous_failed_at' => 'immutable_datetime',
            'requested_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new LogicException('Analysis retry events are immutable.');
        });

        self::deleting(static function (): never {
            throw new LogicException(
                'Analysis retry events cannot be deleted individually.',
            );
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }

    public function previousDispatch(): BelongsTo
    {
        return $this->belongsTo(
            AnalysisDispatch::class,
            'previous_dispatch_id',
        );
    }

    public function newDispatch(): BelongsTo
    {
        return $this->belongsTo(
            AnalysisDispatch::class,
            'new_dispatch_id',
        );
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
