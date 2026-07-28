<?php

namespace App\Models;

use App\Enums\Outcomes\ActualSaleOutcomeType;
use App\Enums\Outcomes\OutcomeEvidenceKind;
use App\Enums\Pricing\ExchangeRateDirection;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ActualSale extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id',
        'owned_product_id',
        'sale_portfolio_entry_id',
        'sale_portfolio_event_id',
        'previous_sale_id',
        'actor_user_id',
        'sequence',
        'outcome_type',
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
        'listed_at',
        'sale_duration_seconds',
        'evidence_kind',
        'evidence_reference',
        'reason_code',
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
            'outcome_type' => ActualSaleOutcomeType::class,
            'source_amount_minor' => 'integer',
            'reporting_amount_minor' => 'integer',
            'rate_direction' => ExchangeRateDirection::class,
            'rate_value' => 'decimal:18',
            'rate_effective_at' => 'immutable_datetime',
            'conversion_calculated_at' => 'immutable_datetime',
            'listed_at' => 'immutable_datetime',
            'sale_duration_seconds' => 'integer',
            'evidence_kind' => OutcomeEvidenceKind::class,
            'evidence_snapshot' => 'array',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Actual sale evidence is immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Actual sale evidence cannot be deleted individually.');
        });
    }

    public function ownedProduct(): BelongsTo
    {
        return $this->belongsTo(OwnedProduct::class);
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(SalePortfolioEntry::class, 'sale_portfolio_entry_id');
    }

    public function portfolioEvent(): BelongsTo
    {
        return $this->belongsTo(SalePortfolioEvent::class, 'sale_portfolio_event_id');
    }

    public function previousSale(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_sale_id');
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
