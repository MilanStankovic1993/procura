<?php

namespace App\Policies;

use App\Enums\Organizations\OrganizationPermission;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\SellComparableMarketNormalization;
use App\Models\User;

final class SellComparableMarketNormalizationPolicy
{
    public function viewAny(
        User $user,
        ?Organization $organization = null,
    ): bool {
        if ($organization === null) {
            return $user->is_super_admin && $user->hasVerifiedEmail();
        }

        return $this->allows($user, $organization);
    }

    public function view(
        User $user,
        SellComparableMarketNormalization $normalization,
    ): bool {
        if ($user->is_super_admin && $user->hasVerifiedEmail()) {
            return true;
        }

        return $this->allows($user, $normalization->organization);
    }

    private function allows(User $user, Organization $organization): bool
    {
        return OrganizationMembership::query()
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $user->getKey())
            ->first()
            ?->role
            ->allows(OrganizationPermission::ViewOwnedProducts) ?? false;
    }
}
