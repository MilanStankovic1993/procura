<?php

namespace App\Policies;

use App\Enums\Organizations\OrganizationPermission;
use App\Models\MarketplaceImport;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;

final class MarketplaceImportPolicy
{
    public function viewAny(
        User $user,
        ?Organization $organization = null,
    ): bool {
        if ($organization === null) {
            return $user->is_super_admin && $user->hasVerifiedEmail();
        }

        return $this->allows(
            $user,
            $organization,
            OrganizationPermission::ViewListings,
        );
    }

    public function create(User $user, Organization $organization): bool
    {
        return $this->allows(
            $user,
            $organization,
            OrganizationPermission::ManageListings,
        );
    }

    public function view(User $user, MarketplaceImport $import): bool
    {
        return $this->allows(
            $user,
            $import->organization,
            OrganizationPermission::ViewListings,
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
