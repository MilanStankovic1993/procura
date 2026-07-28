<?php

namespace App\Models;

use App\Enums\Monitoring\TelegramConnectionEventType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class TelegramConnectionEvent extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'event_type' => TelegramConnectionEventType::class,
            'sequence' => 'integer',
            'payload' => 'array',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException(
                'Telegram connection events are immutable.',
            );
        });

        static::deleting(static function (): never {
            throw new LogicException(
                'Telegram connection events cannot be deleted individually.',
            );
        });
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(
            TelegramConnection::class,
            'telegram_connection_id',
        );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function previousEvent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_event_id');
    }
}
