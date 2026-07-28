<?php

namespace App\Models;

use App\Enums\Privacy\PrivacyRequestActorType;
use App\Enums\Privacy\PrivacyRequestStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class PrivacyRequestEvent extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'privacy_request_id',
        'previous_event_id',
        'actor_user_id',
        'sequence',
        'prior_status',
        'next_status',
        'actor_type',
        'reason_code',
        'note',
        'evidence_reference',
        'idempotency_key',
        'payload_hash',
        'occurred_at',
    ];

    protected $hidden = [
        'payload_hash',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'prior_status' => PrivacyRequestStatus::class,
            'next_status' => PrivacyRequestStatus::class,
            'actor_type' => PrivacyRequestActorType::class,
            'occurred_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new LogicException('Privacy request events are immutable.');
        });
        self::deleting(static function (): never {
            throw new LogicException(
                'Privacy request events cannot be deleted individually.',
            );
        });
    }

    public function privacyRequest(): BelongsTo
    {
        return $this->belongsTo(PrivacyRequest::class);
    }

    public function previousEvent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_event_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
