<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Currency extends Model
{
    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'code',
        'numeric_code',
        'name',
        'symbol',
        'minor_unit',
        'cash_minor_unit',
        'active',
        'source_version',
    ];

    protected function casts(): array
    {
        return [
            'minor_unit' => 'integer',
            'cash_minor_unit' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function countries(): HasMany
    {
        return $this->hasMany(Country::class);
    }

    public function baseExchangeRates(): HasMany
    {
        return $this->hasMany(ExchangeRate::class, 'base_currency_code');
    }

    public function quoteExchangeRates(): HasMany
    {
        return $this->hasMany(ExchangeRate::class, 'quote_currency_code');
    }
}
