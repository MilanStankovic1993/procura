<?php

namespace App\Models;

use App\Enums\Outcomes\ActualCostCategory;
use App\Enums\Outcomes\OutcomeEvidenceKind;
use App\Enums\Pricing\ExchangeRateDirection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ActualCostItem extends Model
{
    use HasUlids;

    protected $fillable = [
        'actual_cost_snapshot_id',
        'position',
        'category',
        'is_known',
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
        'occurred_at',
        'evidence_kind',
        'evidence_reference',
        'note',
        'evidence_hash',
        'evidence_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'category' => ActualCostCategory::class,
            'is_known' => 'boolean',
            'source_amount_minor' => 'integer',
            'reporting_amount_minor' => 'integer',
            'rate_direction' => ExchangeRateDirection::class,
            'rate_value' => 'decimal:18',
            'rate_effective_at' => 'immutable_datetime',
            'conversion_calculated_at' => 'immutable_datetime',
            'occurred_at' => 'immutable_datetime',
            'evidence_kind' => OutcomeEvidenceKind::class,
            'evidence_snapshot' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Actual cost evidence is immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Actual cost evidence cannot be deleted individually.');
        });
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(
            ActualCostSnapshot::class,
            'actual_cost_snapshot_id',
        );
    }

    public function exchangeRate(): BelongsTo
    {
        return $this->belongsTo(ExchangeRate::class);
    }
}
