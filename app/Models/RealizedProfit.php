<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class RealizedProfit extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id',
        'owned_product_id',
        'actual_purchase_id',
        'actual_cost_snapshot_id',
        'actual_sale_id',
        'run_number',
        'calculation_version',
        'calculation_key',
        'input_hash',
        'currency_code',
        'purchase_price_minor',
        'sale_price_minor',
        'actual_costs_minor',
        'total_invested_minor',
        'net_profit_minor',
        'profit_margin_basis_points',
        'return_on_invested_capital_basis_points',
        'sale_duration_seconds',
        'reason_codes',
        'input_snapshot',
        'calculated_at',
    ];

    protected function casts(): array
    {
        return [
            'run_number' => 'integer',
            'purchase_price_minor' => 'integer',
            'sale_price_minor' => 'integer',
            'actual_costs_minor' => 'integer',
            'total_invested_minor' => 'integer',
            'net_profit_minor' => 'integer',
            'profit_margin_basis_points' => 'integer',
            'return_on_invested_capital_basis_points' => 'integer',
            'sale_duration_seconds' => 'integer',
            'reason_codes' => 'array',
            'input_snapshot' => 'array',
            'calculated_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Realized profit calculations are immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Realized profit calculations cannot be deleted individually.');
        });
    }

    public function ownedProduct(): BelongsTo
    {
        return $this->belongsTo(OwnedProduct::class);
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(ActualPurchase::class, 'actual_purchase_id');
    }

    public function costSnapshot(): BelongsTo
    {
        return $this->belongsTo(ActualCostSnapshot::class, 'actual_cost_snapshot_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(ActualSale::class, 'actual_sale_id');
    }

    public function estimateAttributions(): HasMany
    {
        return $this->hasMany(OutcomeEstimateAttribution::class);
    }

    public function accuracyReports(): HasMany
    {
        return $this->hasMany(EstimateAccuracyReport::class);
    }
}
