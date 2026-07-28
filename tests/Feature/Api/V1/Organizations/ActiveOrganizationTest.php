<?php

use App\Actions\Organizations\CreatePersonalOrganization;
use App\Enums\Organizations\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Tenancy\ResolveActiveOrganization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

test('users can list only organizations where they have a membership', function () {
    $user = User::factory()->create();
    $personalOrganization = app(CreatePersonalOrganization::class)->createFor($user);
    $businessOrganization = Organization::factory()->create();
    $outsideOrganization = Organization::factory()->create();

    OrganizationMembership::factory()->create([
        'organization_id' => $businessOrganization,
        'user_id' => $user,
    ]);

    $this->actingAs($user)
        ->getJson(route('api.v1.organizations.index'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonFragment([
            'id' => $personalOrganization->getKey(),
            'role' => OrganizationRole::Owner->value,
            'is_active' => true,
        ])
        ->assertJsonFragment([
            'id' => $businessOrganization->getKey(),
            'role' => OrganizationRole::Analyst->value,
            'is_active' => false,
        ])
        ->assertJsonMissing(['id' => $outsideOrganization->getKey()]);
});

test('users can activate an organization where they have a membership', function () {
    $user = User::factory()->create();
    app(CreatePersonalOrganization::class)->createFor($user);
    $businessOrganization = Organization::factory()->create();

    OrganizationMembership::factory()->create([
        'organization_id' => $businessOrganization,
        'user_id' => $user,
    ]);

    $this->actingAs($user)
        ->putJson(route('api.v1.organizations.activate', $businessOrganization->getKey()))
        ->assertOk()
        ->assertJsonPath('data.id', $businessOrganization->getKey())
        ->assertJsonPath('data.is_active', true);

    expect($user->fresh()->current_organization_id)->toBe($businessOrganization->getKey());

    $this->actingAs($user->fresh())
        ->getJson(route('api.v1.me'))
        ->assertOk()
        ->assertJsonPath('data.current_organization.id', $businessOrganization->getKey());
});

test('a forged organization identifier cannot cross tenant boundaries', function () {
    $user = User::factory()->create();
    $personalOrganization = app(CreatePersonalOrganization::class)->createFor($user);
    $outsideOrganization = Organization::factory()->create();

    $this->actingAs($user)
        ->putJson(route('api.v1.organizations.activate', $outsideOrganization->getKey()))
        ->assertNotFound();

    expect($user->fresh()->current_organization_id)->toBe($personalOrganization->getKey());
});

test('a missing active organization falls back to the oldest membership', function () {
    $user = User::factory()->create();
    $personalOrganization = app(CreatePersonalOrganization::class)->createFor($user);

    $user->current_organization_id = null;
    $user->save();

    $this->actingAs($user->fresh())
        ->getJson(route('api.v1.me'))
        ->assertOk()
        ->assertJsonPath('data.current_organization.id', $personalOrganization->getKey());

    expect($user->fresh()->current_organization_id)->toBe($personalOrganization->getKey());
});

test('a removed active membership falls back without granting cross tenant access', function () {
    $user = User::factory()->create();
    $personalOrganization = app(CreatePersonalOrganization::class)->createFor($user);
    $businessOrganization = Organization::factory()->create();
    $businessMembership = OrganizationMembership::factory()->create([
        'organization_id' => $businessOrganization,
        'user_id' => $user,
    ]);

    $user->current_organization_id = $businessOrganization->getKey();
    $user->save();
    $businessMembership->delete();

    $this->actingAs($user->fresh())
        ->getJson(route('api.v1.me'))
        ->assertOk()
        ->assertJsonPath('data.current_organization.id', $personalOrganization->getKey());

    expect($user->fresh()->current_organization_id)->toBe($personalOrganization->getKey());
});

test('accounts without memberships receive an explicit organization context error', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson(route('api.v1.me'))
        ->assertConflict()
        ->assertJsonPath('code', 'organization_context_unavailable');
});

test('organization policies distinguish members owners and outsiders', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $outsider = User::factory()->create();
    $organization = Organization::factory()->create();

    OrganizationMembership::factory()->owner()->create([
        'organization_id' => $organization,
        'user_id' => $owner,
    ]);
    OrganizationMembership::factory()->create([
        'organization_id' => $organization,
        'user_id' => $member,
    ]);

    expect(Gate::forUser($owner)->allows('view', $organization))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('update', $organization))->toBeTrue()
        ->and(Gate::forUser($member)->allows('view', $organization))->toBeTrue()
        ->and(Gate::forUser($member)->allows('activate', $organization))->toBeTrue()
        ->and(Gate::forUser($member)->allows('update', $organization))->toBeFalse()
        ->and(Gate::forUser($outsider)->allows('view', $organization))->toBeFalse();
});

test('the normal organization resolver path uses a constant number of indexed queries', function () {
    $user = User::factory()->create();
    app(CreatePersonalOrganization::class)->createFor($user);
    $user->refresh();

    DB::flushQueryLog();
    DB::enableQueryLog();

    app(ResolveActiveOrganization::class)->resolve($user);

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toHaveCount(2);
});

test('a stale request never overwrites a concurrent organization selection', function () {
    $user = User::factory()->create();
    $personalOrganization = app(CreatePersonalOrganization::class)->createFor($user);
    $businessOrganization = Organization::factory()->create();
    OrganizationMembership::factory()->create([
        'organization_id' => $businessOrganization,
        'user_id' => $user,
    ]);

    $staleUser = $user->fresh();
    User::query()->whereKey($user->getKey())->update([
        'current_organization_id' => $businessOrganization->getKey(),
    ]);

    $resolvedMembership = app(ResolveActiveOrganization::class)->resolve($staleUser);

    expect($resolvedMembership->organization_id)->toBe($personalOrganization->getKey())
        ->and($user->fresh()->current_organization_id)->toBe($businessOrganization->getKey());
});
