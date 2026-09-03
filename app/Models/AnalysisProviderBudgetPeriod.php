<?php

namespace App\Models;

use App\Enums\Analyses\AnalysisProviderBudgetScope;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class AnalysisProviderBudgetPeriod extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_date',
            'scope_type' => AnalysisProviderBudgetScope::class,
            'reserved_cost_minor' => 'integer',
            'consumed_cost_minor' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (self $period): void {
            if ($period->isDirty([
                'period_start',
                'scope_type',
                'scope_id',
            ])) {
                throw new LogicException('Analysis provider budget identity is immutable.');
            }

            if (
                $period->consumed_cost_minor
                < (int) $period->getRawOriginal('consumed_cost_minor')
            ) {
                throw new LogicException('Consumed analysis provider cost cannot decrease.');
            }
        });

        self::deleting(function (): never {
            throw new LogicException('Analysis provider budget periods cannot be deleted individually.');
        });
    }
}
