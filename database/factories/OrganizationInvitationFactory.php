<?php

namespace Database\Factories;

use App\Enums\Organizations\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OrganizationInvitation>
 */
class OrganizationInvitationFactory extends Factory
{
    protected $model = OrganizationInvitation::class;

    public function definition(): array
    {
        $email = Str::lower(fake()->unique()->safeEmail());

        return [
            'organization_id' => Organization::factory(),
            'email' => $email,
            'pending_email' => $email,
            'role' => OrganizationRole::Analyst,
            'token_hash' => hash('sha256', Str::random(64)),
            'invited_by_user_id' => User::factory(),
            'accepted_by_user_id' => null,
            'expires_at' => now()->addDays(7),
            'accepted_at' => null,
            'revoked_at' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'expires_at' => now()->subMinute(),
        ]);
    }
}
