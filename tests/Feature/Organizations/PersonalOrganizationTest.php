<?php

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Organizations\CreatePersonalOrganization;
use App\Enums\Organizations\OrganizationRole;
use App\Enums\Organizations\OrganizationType;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Database\QueryException;

test('a personal organization has an explicit owner membership and becomes the default context', function () {
    $user = User::factory()->create(['name' => 'Miyuki 山田']);

    $organization = app(CreatePersonalOrganization::class)->createFor($user);
    $membership = $organization->memberships()->sole();

    expect($organization->type)->toBe(OrganizationType::Personal)
        ->and($organization->personal_user_id)->toBe($user->getKey())
        ->and($organization->name)->toBe($user->name)
        ->and($membership->user_id)->toBe($user->getKey())
        ->and($membership->role)->toBe(OrganizationRole::Owner)
        ->and($membership->joined_at)->not->toBeNull()
        ->and($user->fresh()->current_organization_id)->toBe($organization->getKey());
});

test('personal organization creation is idempotent', function () {
    $user = User::factory()->create();
    $creator = app(CreatePersonalOrganization::class);

    $first = $creator->createFor($user);
    $second = $creator->createFor($user);

    expect($second->is($first))->toBeTrue()
        ->and(Organization::query()->count())->toBe(1)
        ->and(OrganizationMembership::query()->count())->toBe(1);
});

test('personal organization creation does not replace an existing active organization', function () {
    $user = User::factory()->create();
    $businessOrganization = Organization::factory()->create();

    OrganizationMembership::factory()->create([
        'organization_id' => $businessOrganization,
        'user_id' => $user,
    ]);
    $user->current_organization_id = $businessOrganization->getKey();
    $user->save();

    $personalOrganization = app(CreatePersonalOrganization::class)->createFor($user);

    expect($user->fresh()->current_organization_id)->toBe($businessOrganization->getKey())
        ->and($personalOrganization->getKey())->not->toBe($businessOrganization->getKey())
        ->and($user->organizations()->count())->toBe(2);
});

test('the deployment backfill is idempotent for users created before organization support', function () {
    $user = User::factory()->create();
    $migration = require database_path(
        'migrations/2026_07_24_101600_backfill_personal_organizations.php',
    );

    $migration->up();
    $migration->up();

    $organization = $user->fresh()->personalOrganization;

    expect($organization)->not->toBeNull()
        ->and($user->fresh()->current_organization_id)->toBe($organization->getKey())
        ->and($organization->memberships()->sole()->role)->toBe(OrganizationRole::Owner)
        ->and(Organization::query()->count())->toBe(1)
        ->and(OrganizationMembership::query()->count())->toBe(1);
});

test('registration rolls back when personal organization creation fails', function () {
    $creator = Mockery::mock(CreatePersonalOrganization::class);
    $creator->shouldReceive('createFor')
        ->once()
        ->andThrow(new RuntimeException('Organization creation failed.'));

    $this->app->instance(CreatePersonalOrganization::class, $creator);

    expect(fn () => app(CreateNewUser::class)->create([
        'name' => 'Rollback User',
        'email' => 'rollback@example.com',
        'password' => 'SecurePass123!',
        'password_confirmation' => 'SecurePass123!',
    ]))->toThrow(RuntimeException::class);

    expect(User::query()->count())->toBe(0)
        ->and(Organization::query()->count())->toBe(0)
        ->and(OrganizationMembership::query()->count())->toBe(0);
});

test('the database prevents duplicate personal organizations for one user', function () {
    $user = User::factory()->create();

    Organization::factory()->personal($user)->create();

    expect(fn () => Organization::factory()->personal($user)->create())
        ->toThrow(QueryException::class);
});

test('the database prevents duplicate memberships', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();

    OrganizationMembership::factory()->create([
        'organization_id' => $organization,
        'user_id' => $user,
    ]);

    expect(fn () => OrganizationMembership::factory()->create([
        'organization_id' => $organization,
        'user_id' => $user,
    ]))->toThrow(QueryException::class);
});

test('organization relationships do not leak memberships across tenants', function () {
    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();
    $firstOrganization = Organization::factory()->create();
    $secondOrganization = Organization::factory()->create();

    OrganizationMembership::factory()->owner()->create([
        'organization_id' => $firstOrganization,
        'user_id' => $firstUser,
    ]);
    OrganizationMembership::factory()->owner()->create([
        'organization_id' => $secondOrganization,
        'user_id' => $secondUser,
    ]);

    expect($firstUser->organizations()->pluck('organizations.id')->all())
        ->toBe([$firstOrganization->getKey()])
        ->not->toContain($secondOrganization->getKey())
        ->and($secondUser->organizations()->pluck('organizations.id')->all())
        ->toBe([$secondOrganization->getKey()])
        ->not->toContain($firstOrganization->getKey());
});
