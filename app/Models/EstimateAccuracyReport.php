<?php

namespace App\Models;

use App\Enums\EstimateAccuracy\EstimateAccuracyStatus;
use App\Enums\Pricing\ExchangeRateDirection;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class EstimateAccuracyReport extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        $casts = [
            'sequence' => 'integer',
            'status' => EstimateAccuracyStatus::class,
            'rate_direction' => ExchangeRateDirection::class,
            'rate_value' => 'decimal:18',
            'rate_effective_at' => 'immutable_datetime',
            'conversion_calculated_at' => 'immutable_datetime',
            'expected_sale_duration_seconds' => 'integer',
            'actual_sale_duration_seconds' => 'integer',
            'sale_duration_signed_error_seconds' => 'integer',
            'sale_duration_absolute_error_seconds' => 'integer',
            'reason_codes' => 'array',
            'unavailable_metrics' => 'array',
            'input_snapshot' => 'array',
            'calculated_at' => 'immutable_datetime',
        ];

        foreach ([
            'purchase_price',
            'additional_costs',
            'sale_price',
            'net_profit',
        ] as $metric) {
            foreach ([
                "source_expected_{$metric}_minor",
                "expected_{$metric}_minor",
                "actual_{$metric}_minor",
                "{$metric}_signed_error_minor",
                "{$metric}_absolute_error_minor",
                "{$metric}_signed_error_basis_points",
                "{$metric}_absolute_percentage_error_basis_points",
            ] as $field) {
                $casts[$field] = 'integer';
            }
        }

        return $casts;
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException(
                'Estimate-accuracy reports are immutable.',
            );
        });
        static::deleting(static function (): never {
            throw new LogicException(
                'Estimate-accuracy reports cannot be deleted individually.',
            );
        });
    }

    public function ownedProduct(): BelongsTo
    {
        return $this->belongsTo(OwnedProduct::class);
    }

    public function attribution(): BelongsTo
    {
        return $this->belongsTo(
            OutcomeEstimateAttribution::class,
            'outcome_estimate_attribution_id',
        );
    }

    public function realizedProfit(): BelongsTo
    {
        return $this->belongsTo(RealizedProfit::class);
    }

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }

    public function profitEstimate(): BelongsTo
    {
        return $this->belongsTo(ProfitEstimate::class);
    }

    public function previousReport(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_report_id');
    }

    public function exchangeRate(): BelongsTo
    {
        return $this->belongsTo(ExchangeRate::class);
    }
}
