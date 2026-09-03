<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class ExchangeRate extends Model
{
    use HasUlids;

    protected $fillable = [
        'base_currency_code',
        'quote_currency_code',
        'rate',
        'provider',
        'provider_reference',
        'rate_key',
        'evidence_hash',
        'raw_evidence',
        'effective_at',
        'published_at',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:18',
            'raw_evidence' => 'array',
            'effective_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'fetched_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Exchange-rate evidence is immutable.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Exchange-rate evidence cannot be deleted individually.');
        });
    }

    public function baseCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'base_currency_code');
    }

    public function quoteCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'quote_currency_code');
    }

    public function priceEstimateItems(): HasMany
    {
        return $this->hasMany(PriceEstimateItem::class);
    }

    public function comparableMarketNormalizations(): HasMany
    {
        return $this->hasMany(ComparableMarketNormalization::class);
    }

    public function sellComparableMarketNormalizations(): HasMany
    {
        return $this->hasMany(SellComparableMarketNormalization::class);
    }
}
