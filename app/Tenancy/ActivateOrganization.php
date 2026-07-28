<?php

namespace App\Tenancy;

use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ActivateOrganization
{
    public function activate(User $user, string $organizationId): OrganizationMembership
    {
        $membership = DB::transaction(function () use ($user, $organizationId) {
            $lockedUser = User::query()
                ->lockForUpdate()
                ->findOrFail($user->getKey());

            $membership = OrganizationMembership::query()
                ->with('organization')
                ->where('user_id', $lockedUser->getKey())
                ->where('organization_id', $organizationId)
                ->firstOrFail();

            if ($lockedUser->current_organization_id !== $membership->organization_id) {
                $lockedUser->current_organization_id = $membership->organization_id;
                $lockedUser->save();
            }

            return $membership;
        });

        $user->current_organization_id = $membership->organization_id;

        return $membership;
    }
}
