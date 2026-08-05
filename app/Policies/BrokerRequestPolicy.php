<?php

namespace App\Policies;

use App\Enums\Organizations\OrganizationPermission;
use App\Models\BrokerRequest;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;

class BrokerRequestPolicy
{
    public function viewAny(
        User $user,
        ?Organization $organization = null,
    ): bool {
        if ($organization === null) {
            return $user->hasVerifiedEmail() && $user->is_super_admin;
        }

        return $this->allows(
            $user,
            $organization,
            OrganizationPermission::ViewBrokerRequests,
        );
    }

    public function create(User $user, Organization $organization): bool
    {
        return $this->allows(
            $user,
            $organization,
            OrganizationPermission::ManageBrokerRequests,
        );
    }

    public function view(User $user, BrokerRequest $request): bool
    {
        return $this->allows(
            $user,
            $request->organization,
            OrganizationPermission::ViewBrokerRequests,
        );
    }

    public function update(User $user, BrokerRequest $request): bool
    {
        return $this->allows(
            $user,
            $request->organization,
            OrganizationPermission::ManageBrokerRequests,
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
