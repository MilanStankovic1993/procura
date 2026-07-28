<?php

namespace App\Policies;

use App\Enums\Organizations\OrganizationPermission;
use App\Enums\Organizations\OrganizationRole;
use App\Enums\Organizations\OrganizationType;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;

class OrganizationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, Organization $organization): bool
    {
        return $this->membershipFor($user, $organization) !== null;
    }

    public function activate(User $user, Organization $organization): bool
    {
        return $this->view($user, $organization);
    }

    public function update(User $user, Organization $organization): bool
    {
        return $this->allows(
            $user,
            $organization,
            OrganizationPermission::UpdateOrganization,
        );
    }

    public function viewMembers(User $user, Organization $organization): bool
    {
        return $this->allows($user, $organization, OrganizationPermission::ViewMembers);
    }

    public function inviteMembers(User $user, Organization $organization): bool
    {
        return $this->allows($user, $organization, OrganizationPermission::InviteMembers);
    }

    public function manageMembers(User $user, Organization $organization): bool
    {
        return $this->allows($user, $organization, OrganizationPermission::ManageMembers);
    }

    public function transferOwnership(User $user, Organization $organization): bool
    {
        return $this->allows($user, $organization, OrganizationPermission::TransferOwnership);
    }

    public function viewAudit(User $user, Organization $organization): bool
    {
        return $this->allows($user, $organization, OrganizationPermission::ViewAudit);
    }

    public function manageBilling(User $user, Organization $organization): bool
    {
        return $this->membershipFor($user, $organization)?->role === OrganizationRole::Owner;
    }

    private function allows(
        User $user,
        Organization $organization,
        OrganizationPermission $permission,
    ): bool {
        if ($organization->type !== OrganizationType::Business) {
            return false;
        }

        return $this->membershipFor($user, $organization)?->role->allows($permission) ?? false;
    }

    private function membershipFor(
        User $user,
        Organization $organization,
    ): ?OrganizationMembership {
        return $user->memberships()
            ->where('organization_id', $organization->getKey())
            ->first();
    }
}
