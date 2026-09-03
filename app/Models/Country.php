<?php

namespace App\Models;

use App\Enums\Markets\MeasurementSystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Country extends Model
{
    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'code',
        'alpha3_code',
        'numeric_code',
        'continent_code',
        'name',
        'currency_code',
        'measurement_system',
        'active',
        'source_version',
    ];

    protected function casts(): array
    {
        return [
            'measurement_system' => MeasurementSystem::class,
            'active' => 'boolean',
        ];
    }

    public function continent(): BelongsTo
    {
        return $this->belongsTo(Continent::class, 'continent_code');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
