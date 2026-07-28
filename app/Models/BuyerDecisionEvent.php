<?php

namespace App\Models;

use App\Enums\BuyerDecisions\BuyerDecisionState;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class BuyerDecisionEvent extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id',
        'analysis_id',
        'deal_score_id',
        'previous_event_id',
        'actor_user_id',
        'sequence',
        'prior_state',
        'next_state',
        'reason_code',
        'note',
        'idempotency_key',
        'payload_hash',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'prior_state' => BuyerDecisionState::class,
            'next_state' => BuyerDecisionState::class,
            'decided_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Buyer decision events are immutable.');
        });

        static::deleting(static function (): never {
            throw new LogicException(
                'Buyer decision events cannot be deleted individually.',
            );
        });
    }

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }

    public function dealScore(): BelongsTo
    {
        return $this->belongsTo(DealScore::class);
    }

    public function previousEvent(): BelongsTo
    {
        return $this->belongsTo(
            self::class,
            'previous_event_id',
        );
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
