<?php

use App\Enums\Organizations\OrganizationAuditEventType;
use App\Enums\Organizations\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationAuditEvent;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Notifications\Organizations\OrganizationInvitationNotification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

test('owners can issue normalized single use invitations without exposing the token', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $organization = Organization::factory()->create();
    OrganizationMembership::factory()->owner()->create([
        'organization_id' => $organization,
        'user_id' => $owner,
    ]);
    $owner->current_organization_id = $organization->getKey();
    $owner->save();

    $this->actingAs($owner)
        ->postJson(route('api.v1.organization.invitations.store'), [
            'email' => '  Invitee@Example.COM ',
            'role' => OrganizationRole::Analyst->value,
        ])
        ->assertCreated()
        ->assertJsonPath('data.email', 'invitee@example.com')
        ->assertJsonPath('data.role', OrganizationRole::Analyst->value)
        ->assertJsonMissingPath('data.token')
        ->assertJsonMissingPath('data.token_hash');

    $invitation = OrganizationInvitation::query()->sole();

    expect($invitation->email)->toBe('invitee@example.com')
        ->and($invitation->pending_email)->toBe('invitee@example.com')
        ->and($invitation->token_hash)->toHaveLength(64)
        ->and($invitation->token_hash)->not->toBeEmpty()
        ->and(OrganizationAuditEvent::query()
            ->where('event', OrganizationAuditEventType::InvitationCreated->value)
            ->where('subject_invitation_id', $invitation->getKey())
            ->exists())->toBeTrue();

    Notification::assertSentOnDemand(OrganizationInvitationNotification::class);
});

test('pending invitation uniqueness is enforced by validation and the database', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $organization = Organization::factory()->create();
    OrganizationMembership::factory()->owner()->create([
        'organization_id' => $organization,
        'user_id' => $owner,
    ]);
    $owner->current_organization_id = $organization->getKey();
    $owner->save();

    $payload = [
        'email' => 'duplicate@example.com',
        'role' => OrganizationRole::Viewer->value,
    ];

    $this->actingAs($owner)
        ->postJson(route('api.v1.organization.invitations.store'), $payload)
        ->assertCreated();

    $this->actingAs($owner)
        ->postJson(route('api.v1.organization.invitations.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');

    expect(fn () => OrganizationInvitation::factory()->create([
        'organization_id' => $organization,
        'email' => 'duplicate@example.com',
        'pending_email' => 'duplicate@example.com',
    ]))->toThrow(QueryException::class);
});

test('analysts cannot invite and forged invitation identifiers cannot cross tenants', function () {
    $owner = User::factory()->create();
    $analyst = User::factory()->create();
    $organization = Organization::factory()->create();
    $outsideOrganization = Organization::factory()->create();

    OrganizationMembership::factory()->owner()->create([
        'organization_id' => $organization,
        'user_id' => $owner,
    ]);
    OrganizationMembership::factory()->create([
        'organization_id' => $organization,
        'user_id' => $analyst,
    ]);
    $outsideInvitation = OrganizationInvitation::factory()->create([
        'organization_id' => $outsideOrganization,
    ]);

    foreach ([$owner, $analyst] as $user) {
        $user->current_organization_id = $organization->getKey();
        $user->save();
    }

    $this->actingAs($analyst)
        ->postJson(route('api.v1.organization.invitations.store'), [
            'email' => 'blocked@example.com',
            'role' => OrganizationRole::Viewer->value,
        ])
        ->assertForbidden();

    $this->actingAs($owner)
        ->deleteJson(route(
            'api.v1.organization.invitations.destroy',
            $outsideInvitation->getKey(),
        ))
        ->assertNotFound();

    expect($outsideInvitation->fresh()->isPending())->toBeTrue();
});

test('an invited account can accept without an existing organization context', function () {
    $organization = Organization::factory()->create();
    $inviter = User::factory()->create();
    $invitee = User::factory()->create(['email' => 'join@example.com']);
    $token = Str::random(64);

    OrganizationMembership::factory()->owner()->create([
        'organization_id' => $organization,
        'user_id' => $inviter,
    ]);
    $invitation = OrganizationInvitation::factory()->create([
        'organization_id' => $organization,
        'email' => $invitee->email,
        'pending_email' => $invitee->email,
        'role' => OrganizationRole::Viewer,
        'token_hash' => hash('sha256', $token),
        'invited_by_user_id' => $inviter,
    ]);

    $this->actingAs($invitee)
        ->postJson(route('api.v1.organization-invitations.accept'), ['token' => $token])
        ->assertOk()
        ->assertJsonPath('data.id', $organization->getKey())
        ->assertJsonPath('data.role', OrganizationRole::Viewer->value)
        ->assertJsonPath('data.is_active', true);

    expect($invitee->fresh()->current_organization_id)->toBe($organization->getKey())
        ->and($invitation->fresh()->isPending())->toBeFalse()
        ->and($invitation->fresh()->accepted_by_user_id)->toBe($invitee->getKey())
        ->and(OrganizationMembership::query()
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $invitee->getKey())
            ->where('role', OrganizationRole::Viewer->value)
            ->exists())->toBeTrue();

    $this->actingAs($invitee)
        ->postJson(route('api.v1.organization-invitations.accept'), ['token' => $token])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('token');
});

test('expired and wrong-account invitations cannot be accepted', function () {
    $organization = Organization::factory()->create();
    $inviter = User::factory()->create();
    $invitee = User::factory()->create(['email' => 'correct@example.com']);
    $wrongUser = User::factory()->create(['email' => 'wrong@example.com']);
    $expiredToken = Str::random(64);
    $wrongAccountToken = Str::random(64);

    $expiredInvitation = OrganizationInvitation::factory()->expired()->create([
        'organization_id' => $organization,
        'email' => $invitee->email,
        'pending_email' => $invitee->email,
        'token_hash' => hash('sha256', $expiredToken),
        'invited_by_user_id' => $inviter,
    ]);
    $wrongAccountInvitation = OrganizationInvitation::factory()->create([
        'organization_id' => $organization,
        'email' => $invitee->email,
        'pending_email' => 'second-correct@example.com',
        'token_hash' => hash('sha256', $wrongAccountToken),
        'invited_by_user_id' => $inviter,
    ]);

    $this->actingAs($invitee)
        ->postJson(route('api.v1.organization-invitations.accept'), [
            'token' => $expiredToken,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('token');

    $this->actingAs($wrongUser)
        ->postJson(route('api.v1.organization-invitations.accept'), [
            'token' => $wrongAccountToken,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('token');

    expect($expiredInvitation->fresh()->pending_email)->toBeNull()
        ->and($wrongAccountInvitation->fresh()->isPending())->toBeTrue()
        ->and(OrganizationMembership::query()
            ->where('organization_id', $organization->getKey())
            ->whereIn('user_id', [$invitee->getKey(), $wrongUser->getKey()])
            ->exists())->toBeFalse();
});
