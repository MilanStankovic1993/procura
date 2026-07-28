<?php

namespace App\Policies;

use App\Enums\Organizations\OrganizationPermission;
use App\Models\Listing;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;

class ListingPolicy
{
    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->allows($user, $organization, OrganizationPermission::ViewListings);
    }

    public function create(User $user, Organization $organization): bool
    {
        return $this->allows($user, $organization, OrganizationPermission::ManageListings);
    }

    public function view(User $user, Listing $listing): bool
    {
        return $this->allows(
            $user,
            $listing->organization,
            OrganizationPermission::ViewListings,
        );
    }

    public function update(User $user, Listing $listing): bool
    {
        return $this->allows(
            $user,
            $listing->organization,
            OrganizationPermission::ManageListings,
        );
    }

    public function manageImages(User $user, Listing $listing): bool
    {
        return $this->update($user, $listing);
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
