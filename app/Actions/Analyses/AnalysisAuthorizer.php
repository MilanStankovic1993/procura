<?php

namespace App\Actions\Analyses;

use App\Enums\Organizations\OrganizationPermission;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class AnalysisAuthorizer
{
    public function authorize(
        Organization $organization,
        User $actor,
        OrganizationPermission $permission,
        bool $lockForUpdate = false,
    ): OrganizationMembership {
        $query = OrganizationMembership::query()
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $actor->getKey());

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $membership = $query->first();

        if ($membership === null || ! $membership->role->allows($permission)) {
            throw new AuthorizationException;
        }

        return $membership;
    }
}
