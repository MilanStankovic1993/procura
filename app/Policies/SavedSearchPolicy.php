<?php

namespace App\Policies;

use App\Enums\Organizations\OrganizationPermission;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\SavedSearch;
use App\Models\User;

class SavedSearchPolicy
{
    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->allows(
            $user,
            $organization,
            OrganizationPermission::ViewSavedSearches,
        );
    }

    public function create(User $user, Organization $organization): bool
    {
        return $this->allows(
            $user,
            $organization,
            OrganizationPermission::ManageSavedSearches,
        );
    }

    public function view(User $user, SavedSearch $savedSearch): bool
    {
        return $this->allows(
            $user,
            $savedSearch->organization,
            OrganizationPermission::ViewSavedSearches,
        );
    }

    public function update(User $user, SavedSearch $savedSearch): bool
    {
        return $this->allows(
            $user,
            $savedSearch->organization,
            OrganizationPermission::ManageSavedSearches,
        );
    }

    private function allows(
        User $user,
        Organization $organization,
        OrganizationPermission $permission,
    ): bool {
        return OrganizationMembership::query()
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $user->getKey())
            ->first()
            ?->role
            ->allows($permission) ?? false;
    }
}
