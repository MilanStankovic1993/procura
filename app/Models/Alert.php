<?php

namespace App\Models;

use App\Enums\Monitoring\AlertType;
use App\Enums\Monitoring\NotificationChannel;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class Alert extends Model
{
    use BelongsToOrganization, HasUlids;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'alert_type' => AlertType::class,
            'payload' => 'array',
            'triggered_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Alerts are immutable.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Alerts cannot be deleted individually.');
        });
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function savedSearch(): BelongsTo
    {
        return $this->belongsTo(SavedSearch::class);
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(
            SavedSearchMatch::class,
            'saved_search_match_id',
        );
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(NotificationLog::class)
            ->orderByDesc('sequence');
    }

    public function currentInAppLog(): HasOne
    {
        return $this->hasOne(NotificationLog::class)
            ->ofMany(
                ['sequence' => 'max', 'id' => 'max'],
                static fn ($query) => $query->where(
                    'channel',
                    NotificationChannel::InApp->value,
                ),
            );
    }

    public function currentEmailLog(): HasOne
    {
        return $this->hasOne(NotificationLog::class)
            ->ofMany(
                ['sequence' => 'max', 'id' => 'max'],
                static fn ($query) => $query->where(
                    'channel',
                    NotificationChannel::Email->value,
                ),
            );
    }

    public function currentTelegramLog(): HasOne
    {
        return $this->hasOne(NotificationLog::class)
            ->ofMany(
                ['sequence' => 'max', 'id' => 'max'],
                static fn ($query) => $query->where(
                    'channel',
                    NotificationChannel::Telegram->value,
                ),
            );
    }
}
