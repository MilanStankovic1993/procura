<?php

namespace App\Models;

use App\Enums\Markets\MeasurementSystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class OrganizationMarketPreference extends Model
{
    protected $primaryKey = 'organization_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'home_country_code',
        'reporting_currency_code',
        'locale',
        'timezone',
        'measurement_system',
        'include_cross_border',
    ];

    protected function casts(): array
    {
        return [
            'measurement_system' => MeasurementSystem::class,
            'include_cross_border' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function homeCountry(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'home_country_code');
    }

    public function reportingCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'reporting_currency_code');
    }

    public function countries(): BelongsToMany
    {
        return $this->belongsToMany(
            Country::class,
            'organization_market_countries',
            'organization_id',
            'country_code',
            'organization_id',
            'code',
        )->withPivot('sort_order')->orderByPivot('sort_order');
    }
}
