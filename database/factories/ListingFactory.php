<?php

namespace Database\Factories;

use App\Enums\Listings\ListingStatus;
use App\Models\Listing;
use App\Models\MarketplaceSource;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Listing>
 */
class ListingFactory extends Factory
{
    protected $model = Listing::class;

    public function definition(): array
    {
        $marketplaceName = fake()->company();

        return [
            'organization_id' => Organization::factory(),
            'marketplace_source_id' => MarketplaceSource::factory(),
            'created_by_user_id' => User::factory(),
            'source_url' => fake()->url(),
            'external_id' => fake()->unique()->bothify('listing-########'),
            'marketplace_name' => $marketplaceName,
            'marketplace_key' => str($marketplaceName)->slug()->limit(80, ''),
            'title' => fake()->sentence(5),
            'description' => fake()->paragraph(),
            'asking_price_minor' => fake()->numberBetween(1000, 500000),
            'currency_code' => 'EUR',
            'seller_information' => fake()->name(),
            'location' => fake()->city(),
            'source_country_code' => 'DE',
            'target_country_code' => 'AT',
            'status' => ListingStatus::Active,
            'notes' => null,
            'raw_input' => [],
        ];
    }
}
