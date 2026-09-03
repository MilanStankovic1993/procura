<?php

namespace Database\Factories;

use App\Enums\Organizations\OrganizationType;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'type' => OrganizationType::Business,
            'personal_user_id' => null,
        ];
    }

    public function personal(?User $user = null): static
    {
        return $this->state(fn (): array => [
            'name' => $user?->name ?? fake()->name(),
            'type' => OrganizationType::Personal,
            'personal_user_id' => $user ?? User::factory(),
        ]);
    }
}
