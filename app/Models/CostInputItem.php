<?php

namespace App\Models;

use App\Enums\Profit\CostCategory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class CostInputItem extends Model
{
    use HasUlids;

    protected $fillable = [
        'cost_input_id',
        'position',
        'category',
        'amount_minor',
        'is_known',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'category' => CostCategory::class,
            'amount_minor' => 'integer',
            'is_known' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Cost-input items are immutable.');
        });

        static::deleting(static function (): never {
            throw new LogicException(
                'Cost-input items cannot be deleted individually.',
            );
        });
    }

    public function costInput(): BelongsTo
    {
        return $this->belongsTo(CostInput::class);
    }
}
