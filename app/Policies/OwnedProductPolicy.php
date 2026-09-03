<?php

namespace App\Policies;

use App\Enums\Organizations\OrganizationPermission;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\OwnedProduct;
use App\Models\User;

class OwnedProductPolicy
{
    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->allows(
            $user,
            $organization,
            OrganizationPermission::ViewOwnedProducts,
        );
    }

    public function create(User $user, Organization $organization): bool
    {
        return $this->allows(
            $user,
            $organization,
            OrganizationPermission::ManageOwnedProducts,
        );
    }

    public function view(User $user, OwnedProduct $ownedProduct): bool
    {
        return $this->allows(
            $user,
            $ownedProduct->organization,
            OrganizationPermission::ViewOwnedProducts,
        );
    }

    public function update(User $user, OwnedProduct $ownedProduct): bool
    {
        return $this->allows(
            $user,
            $ownedProduct->organization,
            OrganizationPermission::ManageOwnedProducts,
        );
    }

    public function manageImages(User $user, OwnedProduct $ownedProduct): bool
    {
        return $this->update($user, $ownedProduct);
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
