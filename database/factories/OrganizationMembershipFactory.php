<?php

namespace Database\Factories;

use App\Enums\Organizations\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationMembership>
 */
class OrganizationMembershipFactory extends Factory
{
    protected $model = OrganizationMembership::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'user_id' => User::factory(),
            'role' => OrganizationRole::Analyst,
            'joined_at' => now(),
        ];
    }

    public function owner(): static
    {
        return $this->state(fn (): array => [
            'role' => OrganizationRole::Owner,
        ]);
    }

    public function administrator(): static
    {
        return $this->state(fn (): array => [
            'role' => OrganizationRole::Administrator,
        ]);
    }

    public function viewer(): static
    {
        return $this->state(fn (): array => [
            'role' => OrganizationRole::Viewer,
        ]);
    }
}
