<?php

namespace Database\Factories;

use App\Enums\Listings\MarketplaceConnectorType;
use App\Models\MarketplaceSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MarketplaceSource>
 */
class MarketplaceSourceFactory extends Factory
{
    protected $model = MarketplaceSource::class;

    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2),
            'name' => fake()->company(),
            'connector_type' => MarketplaceConnectorType::Manual,
            'capabilities' => [],
            'supported_country_codes' => null,
            'supported_currency_codes' => null,
            'supported_language_tags' => null,
            'geographic_coverage' => 'Factory-controlled test evidence.',
            'cross_border_supported' => true,
            'compliance_status' => 'approved',
            'asking_price_only' => true,
            'transaction_price_supported' => false,
            'active' => true,
        ];
    }
}
