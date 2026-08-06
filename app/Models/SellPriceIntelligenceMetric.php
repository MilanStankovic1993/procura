<?php

namespace App\Models;

use App\Enums\Sell\SellPriceIntelligenceMetricOperation;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class SellPriceIntelligenceMetric extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $fillable = [
        'operation',
        'metrics_version',
        'selector_version',
        'algorithm_version',
        'scope_count',
        'candidate_count',
        'included_count',
        'excluded_count',
        'band_input_count',
        'outlier_count',
        'selection_replay_count',
        'price_band_replay_count',
        'scope_discovery_microseconds',
        'comparable_selection_microseconds',
        'selection_persistence_microseconds',
        'price_band_estimation_microseconds',
        'price_band_persistence_microseconds',
        'total_microseconds',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'operation' => SellPriceIntelligenceMetricOperation::class,
            'scope_count' => 'integer',
            'candidate_count' => 'integer',
            'included_count' => 'integer',
            'excluded_count' => 'integer',
            'band_input_count' => 'integer',
            'outlier_count' => 'integer',
            'selection_replay_count' => 'integer',
            'price_band_replay_count' => 'integer',
            'scope_discovery_microseconds' => 'integer',
            'comparable_selection_microseconds' => 'integer',
            'selection_persistence_microseconds' => 'integer',
            'price_band_estimation_microseconds' => 'integer',
            'price_band_persistence_microseconds' => 'integer',
            'total_microseconds' => 'integer',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new LogicException(
                'Sell price-intelligence metrics are immutable.',
            );
        });
        self::deleting(static function (): never {
            throw new LogicException(
                'Sell price-intelligence metrics may only be removed by the retention purge.',
            );
        });
    }
}
