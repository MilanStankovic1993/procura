<?php

namespace App\Models;

use App\Enums\Profit\ProfitEstimateItemKind;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ProfitEstimateItem extends Model
{
    use HasUlids;

    protected $fillable = [
        'profit_estimate_id',
        'cost_input_item_id',
        'position',
        'category',
        'kind',
        'amount_minor',
        'is_known',
        'source_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'kind' => ProfitEstimateItemKind::class,
            'amount_minor' => 'integer',
            'is_known' => 'boolean',
            'source_snapshot' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Profit-estimate items are immutable.');
        });

        static::deleting(static function (): never {
            throw new LogicException(
                'Profit-estimate items cannot be deleted individually.',
            );
        });
    }

    public function profitEstimate(): BelongsTo
    {
        return $this->belongsTo(ProfitEstimate::class);
    }

    public function costInputItem(): BelongsTo
    {
        return $this->belongsTo(CostInputItem::class);
    }
}
