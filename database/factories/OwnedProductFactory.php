<?php

namespace Database\Factories;

use App\Enums\OwnedProducts\CrossBorderPreference;
use App\Enums\OwnedProducts\DesiredSaleSpeed;
use App\Enums\OwnedProducts\OwnedProductCondition;
use App\Enums\OwnedProducts\OwnedProductStatus;
use App\Models\Organization;
use App\Models\OwnedProduct;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OwnedProduct>
 */
class OwnedProductFactory extends Factory
{
    protected $model = OwnedProduct::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'created_by_user_id' => User::factory(),
            'product_category_id' => null,
            'brand_name' => fake()->company(),
            'model_name' => fake()->bothify('Model-####'),
            'condition' => OwnedProductCondition::UsedGood,
            'age_months' => fake()->numberBetween(0, 120),
            'accessories' => ['charger'],
            'defects' => [],
            'purchase_history_known' => false,
            'purchase_history' => null,
            'target_continent_code' => 'EU',
            'cross_border_preference' => CrossBorderPreference::Allowed,
            'desired_sale_speed' => DesiredSaleSpeed::Balanced,
            'status' => OwnedProductStatus::Draft,
            'notes' => null,
            'raw_input' => [],
        ];
    }
}
