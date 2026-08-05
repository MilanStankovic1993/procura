<?php

namespace App\Models;

use App\Enums\Localization\SupportedLocale;
use App\Enums\Privacy\PrivacyRequestStatus;
use App\Enums\Privacy\PrivacyRequestType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

final class PrivacyRequest extends Model
{
    use HasUlids;

    protected $fillable = [
        'subject_user_id',
        'requester_email_hash',
        'type',
        'status',
        'active_key',
        'current_event_id',
        'event_sequence',
        'residence_country_code',
        'preferred_locale',
        'reason',
        'blocking_reason_codes',
        'workflow_version',
        'privacy_notice_version',
        'idempotency_key',
        'payload_hash',
        'requested_at',
        'response_target_at',
        'resolved_at',
    ];

    protected $hidden = [
        'requester_email_hash',
        'active_key',
        'payload_hash',
    ];

    protected function casts(): array
    {
        return [
            'type' => PrivacyRequestType::class,
            'status' => PrivacyRequestStatus::class,
            'preferred_locale' => SupportedLocale::class,
            'blocking_reason_codes' => 'array',
            'event_sequence' => 'integer',
            'requested_at' => 'immutable_datetime',
            'response_target_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::deleting(static function (): never {
            throw new LogicException(
                'Privacy requests cannot be deleted individually.',
            );
        });
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    public function residenceCountry(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'residence_country_code', 'code');
    }

    public function currentEvent(): BelongsTo
    {
        return $this->belongsTo(
            PrivacyRequestEvent::class,
            'current_event_id',
        );
    }

    public function events(): HasMany
    {
        return $this->hasMany(PrivacyRequestEvent::class)
            ->orderByDesc('sequence');
    }

    public function fulfillment(): HasOne
    {
        return $this->hasOne(PrivacyRequestFulfillment::class);
    }
}
