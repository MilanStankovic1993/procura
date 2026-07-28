<?php

namespace App\Models;

use App\Enums\Comparables\MarketCompatibilityStatus;
use App\Enums\Pricing\ExchangeRateDirection;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ComparableMarketNormalization extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id',
        'analysis_id',
        'comparable_record_id',
        'created_by_user_id',
        'exchange_rate_id',
        'normalization_key',
        'evidence_hash',
        'calculation_version',
        'compatibility_status',
        'source_country_code',
        'target_country_code',
        'source_currency_code',
        'target_currency_code',
        'source_amount_minor',
        'converted_amount_minor',
        'market_factor_basis_points',
        'market_adjusted_amount_minor',
        'shipping_minor',
        'import_duty_minor',
        'tax_minor',
        'other_cost_minor',
        'normalized_amount_minor',
        'rate_direction',
        'rate_value',
        'rate_effective_at',
        'rate_provider',
        'rate_provider_reference',
        'evidence_reference',
        'compatibility_note',
        'reason_codes',
        'raw_evidence',
        'observed_at',
    ];

    protected function casts(): array
    {
        return [
            'compatibility_status' => MarketCompatibilityStatus::class,
            'source_amount_minor' => 'integer',
            'converted_amount_minor' => 'integer',
            'market_factor_basis_points' => 'integer',
            'market_adjusted_amount_minor' => 'integer',
            'shipping_minor' => 'integer',
            'import_duty_minor' => 'integer',
            'tax_minor' => 'integer',
            'other_cost_minor' => 'integer',
            'normalized_amount_minor' => 'integer',
            'rate_direction' => ExchangeRateDirection::class,
            'rate_effective_at' => 'immutable_datetime',
            'reason_codes' => 'array',
            'raw_evidence' => 'array',
            'observed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Comparable market-normalization evidence is immutable.');
        });

        static::deleting(static function (): never {
            throw new LogicException(
                'Comparable market-normalization evidence cannot be deleted individually.',
            );
        });
    }

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }

    public function comparableRecord(): BelongsTo
    {
        return $this->belongsTo(ComparableRecord::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function exchangeRate(): BelongsTo
    {
        return $this->belongsTo(ExchangeRate::class);
    }
}
