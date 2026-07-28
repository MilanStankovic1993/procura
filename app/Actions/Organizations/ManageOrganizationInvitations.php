<?php

namespace App\Actions\Organizations;

use App\Enums\Organizations\OrganizationAuditEventType;
use App\Enums\Organizations\OrganizationPermission;
use App\Enums\Organizations\OrganizationRole;
use App\Enums\Organizations\OrganizationType;
use App\Enums\Validation\ApplicationValidationCode;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Notifications\Organizations\OrganizationInvitationNotification;
use App\Support\Validation\ApplicationValidation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class ManageOrganizationInvitations
{
    public function __construct(
        private readonly RecordOrganizationAuditEvent $recordAuditEvent,
    ) {}

    public function invite(
        Organization $organization,
        User $actor,
        string $email,
        OrganizationRole $role,
    ): OrganizationInvitation {
        $normalizedEmail = Str::lower(trim($email));
        $plainTextToken = Str::random(64);

        $invitation = DB::transaction(function () use (
            $organization,
            $actor,
            $normalizedEmail,
            $role,
            $plainTextToken,
        ): OrganizationInvitation {
            $lockedOrganization = Organization::query()
                ->lockForUpdate()
                ->findOrFail($organization->getKey());

            $this->authorize(
                organization: $lockedOrganization,
                actor: $actor,
                permission: OrganizationPermission::InviteMembers,
            );

            if (! in_array($role, OrganizationRole::invitable(), true)) {
                ApplicationValidation::fail(
                    'role',
                    ApplicationValidationCode::OwnershipTransferRequired,
                );
            }

            $alreadyMember = User::query()
                ->whereRaw('LOWER(email) = ?', [$normalizedEmail])
                ->whereHas('memberships', fn ($query) => $query
                    ->where('organization_id', $lockedOrganization->getKey()))
                ->exists();

            if ($alreadyMember) {
                ApplicationValidation::fail(
                    'email',
                    ApplicationValidationCode::InvitationAlreadyMember,
                );
            }

            if (OrganizationInvitation::query()
                ->where('organization_id', $lockedOrganization->getKey())
                ->where('pending_email', $normalizedEmail)
                ->exists()) {
                ApplicationValidation::fail(
                    'email',
                    ApplicationValidationCode::InvitationPending,
                );
            }

            $invitation = OrganizationInvitation::query()->create([
                'organization_id' => $lockedOrganization->getKey(),
                'email' => $normalizedEmail,
                'pending_email' => $normalizedEmail,
                'role' => $role,
                'token_hash' => hash('sha256', $plainTextToken),
                'invited_by_user_id' => $actor->getKey(),
                'expires_at' => now()->addDays(7),
            ]);

            $this->recordAuditEvent->record(
                organization: $lockedOrganization,
                event: OrganizationAuditEventType::InvitationCreated,
                actor: $actor,
                subjectInvitation: $invitation,
                metadata: [
                    'email' => $normalizedEmail,
                    'role' => $role->value,
                ],
            );

            return $invitation->setRelation('invitedBy', $actor);
        });

        Notification::route('mail', $invitation->email)->notify(
            new OrganizationInvitationNotification(
                organizationName: $organization->name,
                role: $role,
                plainTextToken: $plainTextToken,
            ),
        );

        return $invitation;
    }

    public function revoke(
        Organization $organization,
        User $actor,
        string $invitationId,
    ): void {
        DB::transaction(function () use ($organization, $actor, $invitationId): void {
            $lockedOrganization = Organization::query()
                ->lockForUpdate()
                ->findOrFail($organization->getKey());

            $this->authorize(
                organization: $lockedOrganization,
                actor: $actor,
                permission: OrganizationPermission::InviteMembers,
            );

            $invitation = OrganizationInvitation::query()
                ->where('organization_id', $lockedOrganization->getKey())
                ->whereNotNull('pending_email')
                ->lockForUpdate()
                ->findOrFail($invitationId);

            $invitation->pending_email = null;
            $invitation->revoked_at = now();
            $invitation->save();

            $this->recordAuditEvent->record(
                organization: $lockedOrganization,
                event: OrganizationAuditEventType::InvitationRevoked,
                actor: $actor,
                subjectInvitation: $invitation,
                metadata: [
                    'email' => $invitation->email,
                    'role' => $invitation->role->value,
                ],
            );
        });
    }

    public function accept(User $user, string $plainTextToken): OrganizationMembership
    {
        $reference = OrganizationInvitation::query()
            ->select(['id', 'organization_id'])
            ->where('token_hash', hash('sha256', $plainTextToken))
            ->firstOrFail();

        $membership = DB::transaction(function () use ($user, $reference): ?OrganizationMembership {
            $organization = Organization::query()
                ->lockForUpdate()
                ->findOrFail($reference->organization_id);

            $lockedUser = User::query()
                ->lockForUpdate()
                ->findOrFail($user->getKey());

            $invitation = OrganizationInvitation::query()
                ->where('organization_id', $organization->getKey())
                ->lockForUpdate()
                ->findOrFail($reference->getKey());

            if (! $invitation->isPending()) {
                ApplicationValidation::fail(
                    'token',
                    ApplicationValidationCode::InvitationUnavailable,
                );
            }

            if ($invitation->expires_at->isPast()) {
                $invitation->pending_email = null;
                $invitation->save();

                return null;
            }

            if (! hash_equals($invitation->email, Str::lower($lockedUser->email))) {
                ApplicationValidation::fail(
                    'token',
                    ApplicationValidationCode::InvitationWrongAccount,
                );
            }

            $membership = OrganizationMembership::query()
                ->where('organization_id', $organization->getKey())
                ->where('user_id', $lockedUser->getKey())
                ->lockForUpdate()
                ->first();

            $alreadyMember = $membership !== null;

            if ($membership === null) {
                $membership = OrganizationMembership::query()->create([
                    'organization_id' => $organization->getKey(),
                    'user_id' => $lockedUser->getKey(),
                    'role' => $invitation->role,
                    'joined_at' => now(),
                ]);
            }

            $invitation->pending_email = null;
            $invitation->accepted_by_user_id = $lockedUser->getKey();
            $invitation->accepted_at = now();
            $invitation->save();

            $lockedUser->current_organization_id = $organization->getKey();
            $lockedUser->save();

            $this->recordAuditEvent->record(
                organization: $organization,
                event: OrganizationAuditEventType::InvitationAccepted,
                actor: $lockedUser,
                subjectUser: $lockedUser,
                subjectInvitation: $invitation,
                metadata: [
                    'email' => $invitation->email,
                    'role' => $membership->role->value,
                    'membership_already_existed' => $alreadyMember,
                ],
            );

            return $membership->setRelation('organization', $organization);
        });

        if ($membership === null) {
            ApplicationValidation::fail(
                'token',
                ApplicationValidationCode::InvitationExpired,
            );
        }

        $user->current_organization_id = $membership->organization_id;

        return $membership;
    }

    private function authorize(
        Organization $organization,
        User $actor,
        OrganizationPermission $permission,
    ): OrganizationMembership {
        $membership = OrganizationMembership::query()
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $actor->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        if ($organization->type !== OrganizationType::Business
            || ! $membership->role->allows($permission)) {
            throw new AuthorizationException;
        }

        return $membership;
    }
}
