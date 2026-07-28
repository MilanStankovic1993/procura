<?php

use App\Enums\Organizations\OrganizationAuditEventType;
use App\Enums\Organizations\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationAuditEvent;
use App\Models\OrganizationMembership;
use App\Models\User;

test('owners can change and remove non-owner members with an audit trail', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $organization = Organization::factory()->create();
    OrganizationMembership::factory()->owner()->create([
        'organization_id' => $organization,
        'user_id' => $owner,
    ]);
    $memberMembership = OrganizationMembership::factory()->create([
        'organization_id' => $organization,
        'user_id' => $member,
    ]);

    foreach ([$owner, $member] as $user) {
        $user->current_organization_id = $organization->getKey();
        $user->save();
    }

    $this->actingAs($owner)
        ->patchJson(route('api.v1.organization.members.update', $memberMembership->getKey()), [
            'role' => OrganizationRole::Viewer->value,
        ])
        ->assertOk()
        ->assertJsonPath('data.role', OrganizationRole::Viewer->value);

    $this->actingAs($owner)
        ->deleteJson(route(
            'api.v1.organization.members.destroy',
            $memberMembership->getKey(),
        ))
        ->assertNoContent();

    expect($memberMembership->fresh())->toBeNull()
        ->and($member->fresh()->current_organization_id)->toBeNull()
        ->and(OrganizationAuditEvent::query()
            ->where('organization_id', $organization->getKey())
            ->where('event', OrganizationAuditEventType::MemberRoleChanged->value)
            ->exists())->toBeTrue()
        ->and(OrganizationAuditEvent::query()
            ->where('organization_id', $organization->getKey())
            ->where('event', OrganizationAuditEventType::MemberRemoved->value)
            ->exists())->toBeTrue();
});

test('administrators can manage analysts and viewers but not privileged members', function () {
    $owner = User::factory()->create();
    $administrator = User::factory()->create();
    $secondAdministrator = User::factory()->create();
    $analyst = User::factory()->create();
    $organization = Organization::factory()->create();

    $ownerMembership = OrganizationMembership::factory()->owner()->create([
        'organization_id' => $organization,
        'user_id' => $owner,
    ]);
    OrganizationMembership::factory()->administrator()->create([
        'organization_id' => $organization,
        'user_id' => $administrator,
    ]);
    $secondAdministratorMembership = OrganizationMembership::factory()->administrator()->create([
        'organization_id' => $organization,
        'user_id' => $secondAdministrator,
    ]);
    $analystMembership = OrganizationMembership::factory()->create([
        'organization_id' => $organization,
        'user_id' => $analyst,
    ]);

    $administrator->current_organization_id = $organization->getKey();
    $administrator->save();

    $this->actingAs($administrator)
        ->patchJson(route('api.v1.organization.members.update', $analystMembership->getKey()), [
            'role' => OrganizationRole::Viewer->value,
        ])
        ->assertOk();

    $this->actingAs($administrator)
        ->patchJson(route(
            'api.v1.organization.members.update',
            $secondAdministratorMembership->getKey(),
        ), [
            'role' => OrganizationRole::Viewer->value,
        ])
        ->assertForbidden();

    $this->actingAs($administrator)
        ->deleteJson(route(
            'api.v1.organization.members.destroy',
            $ownerMembership->getKey(),
        ))
        ->assertForbidden();

    expect($secondAdministratorMembership->fresh()->role)
        ->toBe(OrganizationRole::Administrator)
        ->and($ownerMembership->fresh()->role)->toBe(OrganizationRole::Owner);
});

test('ownership transfer atomically leaves exactly one owner', function () {
    $owner = User::factory()->create();
    $successor = User::factory()->create();
    $organization = Organization::factory()->create();
    $ownerMembership = OrganizationMembership::factory()->owner()->create([
        'organization_id' => $organization,
        'user_id' => $owner,
    ]);
    $successorMembership = OrganizationMembership::factory()->create([
        'organization_id' => $organization,
        'user_id' => $successor,
    ]);
    $owner->current_organization_id = $organization->getKey();
    $owner->save();

    $this->actingAs($owner)
        ->postJson(route(
            'api.v1.organization.ownership.transfer',
            $successorMembership->getKey(),
        ))
        ->assertOk()
        ->assertJsonPath('data.role', OrganizationRole::Owner->value);

    expect($ownerMembership->fresh()->role)->toBe(OrganizationRole::Administrator)
        ->and($successorMembership->fresh()->role)->toBe(OrganizationRole::Owner)
        ->and(OrganizationMembership::query()
            ->where('organization_id', $organization->getKey())
            ->where('role', OrganizationRole::Owner->value)
            ->count())->toBe(1)
        ->and(OrganizationAuditEvent::query()
            ->where('organization_id', $organization->getKey())
            ->where('event', OrganizationAuditEventType::OwnershipTransferred->value)
            ->exists())->toBeTrue();

    $this->actingAs($owner->fresh())
        ->postJson(route(
            'api.v1.organization.ownership.transfer',
            $successorMembership->getKey(),
        ))
        ->assertForbidden();
});
