<?php

namespace App\Models;

use App\Enums\Monitoring\TelegramConnectionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class TelegramConnection extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected $hidden = [
        'challenge_token',
        'telegram_user_id',
        'chat_id',
        'username',
    ];

    protected function casts(): array
    {
        return [
            'status' => TelegramConnectionStatus::class,
            'challenge_token' => 'encrypted',
            'telegram_user_id' => 'encrypted',
            'chat_id' => 'encrypted',
            'username' => 'encrypted',
            'challenge_expires_at' => 'immutable_datetime',
            'connected_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(static function (): never {
            throw new LogicException(
                'Telegram connections must be revoked, not deleted.',
            );
        });
    }

    public function scopeConnected(Builder $query): Builder
    {
        return $query->where(
            'status',
            TelegramConnectionStatus::Connected,
        );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(TelegramConnectionEvent::class)
            ->orderByDesc('sequence');
    }
}
