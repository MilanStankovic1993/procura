<?php

namespace App\Tenancy;

use App\Exceptions\Tenancy\ActiveOrganizationUnavailable;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ResolveActiveOrganization
{
    public function resolve(User $user): OrganizationMembership
    {
        if ($user->current_organization_id !== null) {
            $membership = $this->membershipQuery($user)
                ->where('organization_id', $user->current_organization_id)
                ->first();

            if ($membership !== null) {
                return $membership;
            }
        }

        return $this->recover($user);
    }

    private function recover(User $user): OrganizationMembership
    {
        $membership = DB::transaction(function () use ($user): OrganizationMembership {
            $lockedUser = User::query()
                ->lockForUpdate()
                ->findOrFail($user->getKey());

            $membership = null;

            if ($lockedUser->current_organization_id !== null) {
                $membership = $this->membershipQuery($lockedUser)
                    ->where('organization_id', $lockedUser->current_organization_id)
                    ->first();
            }

            $membership ??= $this->membershipQuery($lockedUser)
                ->orderBy('joined_at')
                ->orderBy('id')
                ->first();

            if ($membership === null) {
                throw new ActiveOrganizationUnavailable(
                    'No organization membership is available for the authenticated user.',
                );
            }

            if ($lockedUser->current_organization_id !== $membership->organization_id) {
                $lockedUser->current_organization_id = $membership->organization_id;
                $lockedUser->save();
            }

            return $membership;
        });

        $user->current_organization_id = $membership->organization_id;

        return $membership;
    }

    /**
     * @return Builder<OrganizationMembership>
     */
    private function membershipQuery(User $user): Builder
    {
        return OrganizationMembership::query()
            ->with('organization')
            ->where('user_id', $user->getKey());
    }
}
