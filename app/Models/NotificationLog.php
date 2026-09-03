<?php

namespace App\Models;

use App\Enums\Monitoring\NotificationChannel;
use App\Enums\Monitoring\NotificationEventType;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class NotificationLog extends Model
{
    use BelongsToOrganization, HasUlids;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'event_type' => NotificationEventType::class,
            'sequence' => 'integer',
            'payload' => 'array',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Notification logs are immutable.');
        });

        static::deleting(static function (): never {
            throw new LogicException(
                'Notification logs cannot be deleted individually.',
            );
        });
    }

    public function alert(): BelongsTo
    {
        return $this->belongsTo(Alert::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function previousLog(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_log_id');
    }
}
