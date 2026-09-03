<?php

namespace App\Models;

use App\Enums\Listings\MarketplaceConnectorType;
use Database\Factories\MarketplaceSourceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketplaceSource extends Model
{
    /** @use HasFactory<MarketplaceSourceFactory> */
    use HasFactory, HasUlids;

    public const MANUAL_KEY = 'manual';

    public const AUTHORIZED_CSV_KEY = 'authorized_csv';

    protected $fillable = [
        'key',
        'name',
        'connector_type',
        'capabilities',
        'supported_country_codes',
        'supported_currency_codes',
        'supported_language_tags',
        'geographic_coverage',
        'cross_border_supported',
        'compliance_status',
        'terms_reviewed_at',
        'legal_basis',
        'allowed_operations',
        'prohibited_operations',
        'rate_limits',
        'data_retention_rules',
        'attribution_rules',
        'contact_person',
        'review_notes',
        'reliability_score',
        'freshness_score',
        'completeness_score',
        'asking_price_only',
        'transaction_price_supported',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'connector_type' => MarketplaceConnectorType::class,
            'capabilities' => 'array',
            'supported_country_codes' => 'array',
            'supported_currency_codes' => 'array',
            'supported_language_tags' => 'array',
            'cross_border_supported' => 'boolean',
            'terms_reviewed_at' => 'immutable_date',
            'reliability_score' => 'integer',
            'freshness_score' => 'integer',
            'completeness_score' => 'integer',
            'asking_price_only' => 'boolean',
            'transaction_price_supported' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    public function imports(): HasMany
    {
        return $this->hasMany(MarketplaceImport::class);
    }

    public function comparableRecords(): HasMany
    {
        return $this->hasMany(ComparableRecord::class);
    }

    public function sellComparableRecords(): HasMany
    {
        return $this->hasMany(SellComparableRecord::class);
    }
}
