<?php

namespace App\Models;

use App\Enums\Outcomes\OutcomeEvidenceKind;
use App\Enums\Pricing\ExchangeRateDirection;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ActualPurchase extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id',
        'owned_product_id',
        'previous_purchase_id',
        'actor_user_id',
        'sequence',
        'source_amount_minor',
        'source_currency_code',
        'reporting_amount_minor',
        'reporting_currency_code',
        'exchange_rate_id',
        'rate_direction',
        'rate_value',
        'rate_effective_at',
        'rate_provider',
        'rate_provider_reference',
        'conversion_calculated_at',
        'evidence_kind',
        'evidence_reference',
        'correction_reason',
        'note',
        'idempotency_key',
        'payload_hash',
        'input_hash',
        'evidence_snapshot',
        'occurred_at',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'source_amount_minor' => 'integer',
            'reporting_amount_minor' => 'integer',
            'rate_direction' => ExchangeRateDirection::class,
            'rate_value' => 'decimal:18',
            'rate_effective_at' => 'immutable_datetime',
            'conversion_calculated_at' => 'immutable_datetime',
            'evidence_kind' => OutcomeEvidenceKind::class,
            'evidence_snapshot' => 'array',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Actual purchase evidence is immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Actual purchase evidence cannot be deleted individually.');
        });
    }

    public function ownedProduct(): BelongsTo
    {
        return $this->belongsTo(OwnedProduct::class);
    }

    public function previousPurchase(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_purchase_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function exchangeRate(): BelongsTo
    {
        return $this->belongsTo(ExchangeRate::class);
    }
}
