<?php

namespace App\Models;

use App\Enums\Analyses\AnalysisProviderCircuitState;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class AnalysisProviderCircuit extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'state' => AnalysisProviderCircuitState::class,
            'consecutive_failures' => 'integer',
            'opened_at' => 'immutable_datetime',
            'retry_at' => 'immutable_datetime',
            'last_failure_at' => 'immutable_datetime',
            'last_success_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (self $circuit): void {
            if ($circuit->isDirty(['provider', 'model'])) {
                throw new LogicException('Analysis provider circuit identity is immutable.');
            }
        });

        self::deleting(function (): never {
            throw new LogicException('Analysis provider circuits cannot be deleted individually.');
        });
    }
}
