<?php

namespace App\Policies;

use App\Enums\Organizations\OrganizationPermission;
use App\Models\Analysis;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;

class AnalysisPolicy
{
    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->allows($user, $organization, OrganizationPermission::ViewAnalyses);
    }

    public function create(User $user, Organization $organization): bool
    {
        return $this->allows($user, $organization, OrganizationPermission::ManageAnalyses);
    }

    public function view(User $user, Analysis $analysis): bool
    {
        return $this->allows(
            $user,
            $analysis->organization,
            OrganizationPermission::ViewAnalyses,
        );
    }

    public function submit(User $user, Analysis $analysis): bool
    {
        return $this->allows(
            $user,
            $analysis->organization,
            OrganizationPermission::ManageAnalyses,
        );
    }

    private function allows(
        User $user,
        Organization $organization,
        OrganizationPermission $permission,
    ): bool {
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
