<?php

use App\Actions\Organizations\CreatePersonalOrganization;
use App\Enums\Organizations\OrganizationAuditEventType;
use App\Enums\Organizations\OrganizationPermission;
use App\Enums\Organizations\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationAuditEvent;
use App\Models\OrganizationMembership;
use App\Models\User;

test('authenticated users can create a business workspace transactionally', function () {
    $user = User::factory()->create();
    app(CreatePersonalOrganization::class)->createFor($user);

    $response = $this->actingAs($user)
        ->postJson(route('api.v1.organizations.store'), [
            'name' => '  Procura Europe  ',
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Procura Europe')
        ->assertJsonPath('data.type', 'business')
        ->assertJsonPath('data.role', OrganizationRole::Owner->value)
        ->assertJsonPath('data.is_active', true);

    $organizationId = $response->json('data.id');

    expect($user->fresh()->current_organization_id)->toBe($organizationId)
        ->and(OrganizationMembership::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $user->getKey())
            ->where('role', OrganizationRole::Owner->value)
            ->exists())->toBeTrue()
        ->and(OrganizationAuditEvent::query()
            ->where('organization_id', $organizationId)
            ->where('event', OrganizationAuditEventType::OrganizationCreated->value)
            ->exists())->toBeTrue();
});

test('organization management returns only the active workspace boundary', function () {
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

    $owner->current_organization_id = $organization->getKey();
    $owner->save();

    $this->actingAs($owner)
        ->getJson(route('api.v1.organization.management'))
        ->assertOk()
        ->assertJsonPath('data.organization.id', $organization->getKey())
        ->assertJsonCount(2, 'data.members')
        ->assertJsonFragment([
            'email' => $owner->email,
            'role' => OrganizationRole::Owner->value,
        ])
        ->assertJsonFragment([
            'email' => $member->email,
            'role' => OrganizationRole::Analyst->value,
        ])
        ->assertJsonMissing(['email' => $outsider->email])
        ->assertJsonPath(
            'data.capabilities.0',
            OrganizationPermission::UpdateOrganization->value,
        );
});

test('personal workspaces cannot expose business management operations', function () {
    $user = User::factory()->create();
    app(CreatePersonalOrganization::class)->createFor($user);

    $this->actingAs($user)
        ->getJson(route('api.v1.organization.management'))
        ->assertForbidden();
});

test('owners and administrators can rename a business workspace but analysts cannot', function () {
    $owner = User::factory()->create();
    $administrator = User::factory()->create();
    $analyst = User::factory()->create();
    $organization = Organization::factory()->create(['name' => 'Original']);

    OrganizationMembership::factory()->owner()->create([
        'organization_id' => $organization,
        'user_id' => $owner,
    ]);
    OrganizationMembership::factory()->administrator()->create([
        'organization_id' => $organization,
        'user_id' => $administrator,
    ]);
    OrganizationMembership::factory()->create([
        'organization_id' => $organization,
        'user_id' => $analyst,
    ]);

    foreach ([$owner, $administrator, $analyst] as $user) {
        $user->current_organization_id = $organization->getKey();
        $user->save();
    }

    $this->actingAs($administrator)
        ->patchJson(route('api.v1.organization.update'), ['name' => 'Global Intelligence'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Global Intelligence');

    $this->actingAs($analyst)
        ->patchJson(route('api.v1.organization.update'), ['name' => 'Forged'])
        ->assertForbidden();

    expect($organization->fresh()->name)->toBe('Global Intelligence')
        ->and(OrganizationAuditEvent::query()
            ->where('organization_id', $organization->getKey())
            ->where('event', OrganizationAuditEventType::OrganizationRenamed->value)
            ->exists())->toBeTrue();
});
