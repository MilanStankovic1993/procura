<?php

namespace App\Actions\Organizations;

use App\Enums\Organizations\OrganizationAuditEventType;
use App\Enums\Organizations\OrganizationPermission;
use App\Enums\Organizations\OrganizationRole;
use App\Enums\Organizations\OrganizationType;
use App\Enums\Validation\ApplicationValidationCode;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Support\Validation\ApplicationValidation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class ManageOrganizationMembers
{
    public function __construct(
        private readonly RecordOrganizationAuditEvent $recordAuditEvent,
    ) {}

    public function updateRole(
        Organization $organization,
        User $actor,
        string $membershipId,
        OrganizationRole $newRole,
    ): OrganizationMembership {
        return DB::transaction(function () use (
            $organization,
            $actor,
            $membershipId,
            $newRole,
        ): OrganizationMembership {
            $lockedOrganization = $this->lockBusinessOrganization($organization);
            $actorMembership = $this->lockActorMembership($lockedOrganization, $actor);
            $targetMembership = $this->lockTargetMembership(
                $lockedOrganization,
                $membershipId,
            );

            if ($newRole === OrganizationRole::Owner) {
                ApplicationValidation::fail(
                    'role',
                    ApplicationValidationCode::OwnershipTransferRequired,
                );
            }

            if (! $this->canManage($actorMembership->role, $targetMembership->role, $newRole)) {
                throw new AuthorizationException;
            }

            $previousRole = $targetMembership->role;

            if ($previousRole === $newRole) {
                return $targetMembership;
            }

            $targetMembership->role = $newRole;
            $targetMembership->save();

            $this->recordAuditEvent->record(
                organization: $lockedOrganization,
                event: OrganizationAuditEventType::MemberRoleChanged,
                actor: $actor,
                subjectUser: $targetMembership->user,
                metadata: [
                    'previous_role' => $previousRole->value,
                    'new_role' => $newRole->value,
                ],
            );

            return $targetMembership;
        });
    }

    public function remove(
        Organization $organization,
        User $actor,
        string $membershipId,
    ): void {
        DB::transaction(function () use ($organization, $actor, $membershipId): void {
            $lockedOrganization = $this->lockBusinessOrganization($organization);
            $actorMembership = $this->lockActorMembership($lockedOrganization, $actor);
            $targetMembership = $this->lockTargetMembership(
                $lockedOrganization,
                $membershipId,
            );

            if (! $this->canManage(
                $actorMembership->role,
                $targetMembership->role,
                $targetMembership->role,
            )) {
                throw new AuthorizationException;
            }

            $targetUser = User::query()
                ->lockForUpdate()
                ->findOrFail($targetMembership->user_id);
            $removedRole = $targetMembership->role;

            $targetMembership->delete();

            if ($targetUser->current_organization_id === $lockedOrganization->getKey()) {
                $targetUser->current_organization_id = null;
                $targetUser->save();
            }

            $this->recordAuditEvent->record(
                organization: $lockedOrganization,
                event: OrganizationAuditEventType::MemberRemoved,
                actor: $actor,
                subjectUser: $targetUser,
                metadata: [
                    'role' => $removedRole->value,
                ],
            );
        });
    }

    public function transferOwnership(
        Organization $organization,
        User $actor,
        string $membershipId,
    ): OrganizationMembership {
        return DB::transaction(function () use (
            $organization,
            $actor,
            $membershipId,
        ): OrganizationMembership {
            $lockedOrganization = $this->lockBusinessOrganization($organization);
            $actorMembership = $this->lockActorMembership($lockedOrganization, $actor);
            $targetMembership = $this->lockTargetMembership(
                $lockedOrganization,
                $membershipId,
            );

            if (
                ! $actorMembership->role->allows(OrganizationPermission::TransferOwnership)
                || $targetMembership->user_id === $actor->getKey()
                || $targetMembership->role === OrganizationRole::Owner
            ) {
                throw new AuthorizationException;
            }

            $ownerCount = OrganizationMembership::query()
                ->where('organization_id', $lockedOrganization->getKey())
                ->where('role', OrganizationRole::Owner->value)
                ->count();

            if ($ownerCount !== 1) {
                ApplicationValidation::fail(
                    'membership',
                    ApplicationValidationCode::OwnershipStateInvalid,
                );
            }

            $previousTargetRole = $targetMembership->role;

            $actorMembership->role = OrganizationRole::Administrator;
            $actorMembership->save();

            $targetMembership->role = OrganizationRole::Owner;
            $targetMembership->save();

            $this->recordAuditEvent->record(
                organization: $lockedOrganization,
                event: OrganizationAuditEventType::OwnershipTransferred,
                actor: $actor,
                subjectUser: $targetMembership->user,
                metadata: [
                    'previous_owner_role' => OrganizationRole::Administrator->value,
                    'previous_target_role' => $previousTargetRole->value,
                ],
            );

            return $targetMembership;
        });
    }

    private function lockBusinessOrganization(Organization $organization): Organization
    {
        $lockedOrganization = Organization::query()
            ->lockForUpdate()
            ->findOrFail($organization->getKey());

        if ($lockedOrganization->type !== OrganizationType::Business) {
            throw new AuthorizationException;
        }

        return $lockedOrganization;
    }

    private function lockActorMembership(
        Organization $organization,
        User $actor,
    ): OrganizationMembership {
        $membership = OrganizationMembership::query()
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $actor->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        if (! $membership->role->allows(OrganizationPermission::ManageMembers)) {
            throw new AuthorizationException;
        }

        return $membership;
    }

    private function lockTargetMembership(
        Organization $organization,
        string $membershipId,
    ): OrganizationMembership {
        return OrganizationMembership::query()
            ->with('user')
            ->where('organization_id', $organization->getKey())
            ->lockForUpdate()
            ->findOrFail($membershipId);
    }

    private function canManage(
        OrganizationRole $actorRole,
        OrganizationRole $targetRole,
        OrganizationRole $newRole,
    ): bool {
        if ($targetRole === OrganizationRole::Owner) {
            return false;
        }

        if ($actorRole === OrganizationRole::Owner) {
            return true;
        }

        return $actorRole === OrganizationRole::Administrator
            && in_array($targetRole, [OrganizationRole::Analyst, OrganizationRole::Viewer], true)
            && in_array($newRole, [OrganizationRole::Analyst, OrganizationRole::Viewer], true);
    }
}
