<?php

namespace App\Models;

use App\Enums\Pricing\ExchangeRateDirection;
use App\Enums\Pricing\PriceEstimateItemDecision;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PriceEstimateItem extends Model
{
    use HasUlids;

    protected $fillable = [
        'price_estimate_id',
        'comparable_set_item_id',
        'comparable_record_id',
        'exchange_rate_id',
        'position',
        'decision',
        'original_amount_minor',
        'original_currency_code',
        'target_amount_minor',
        'target_currency_code',
        'weight_basis_points',
        'rate_direction',
        'rate_value',
        'rate_effective_at',
        'rate_provider',
        'rate_provider_reference',
        'reason_codes',
        'evidence_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'decision' => PriceEstimateItemDecision::class,
            'original_amount_minor' => 'integer',
            'target_amount_minor' => 'integer',
            'weight_basis_points' => 'integer',
            'rate_direction' => ExchangeRateDirection::class,
            'rate_value' => 'decimal:18',
            'rate_effective_at' => 'immutable_datetime',
            'reason_codes' => 'array',
            'evidence_snapshot' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Price-estimate items are immutable.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Price-estimate items cannot be deleted individually.');
        });
    }

    public function priceEstimate(): BelongsTo
    {
        return $this->belongsTo(PriceEstimate::class);
    }

    public function comparableSetItem(): BelongsTo
    {
        return $this->belongsTo(ComparableSetItem::class);
    }

    public function comparableRecord(): BelongsTo
    {
        return $this->belongsTo(ComparableRecord::class);
    }

    public function exchangeRate(): BelongsTo
    {
        return $this->belongsTo(ExchangeRate::class);
    }
}
