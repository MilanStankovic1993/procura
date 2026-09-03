<?php

namespace App\Actions\Organizations;

use App\Enums\Organizations\OrganizationRole;
use App\Enums\Organizations\OrganizationType;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreatePersonalOrganization
{
    public function createFor(User $user): Organization
    {
        $organization = DB::transaction(function () use ($user): Organization {
            $lockedUser = User::query()
                ->lockForUpdate()
                ->findOrFail($user->getKey());

            $organization = Organization::query()->firstOrCreate(
                ['personal_user_id' => $lockedUser->getKey()],
                [
                    'name' => $lockedUser->name,
                    'type' => OrganizationType::Personal,
                ],
            );

            $membership = OrganizationMembership::query()->firstOrNew([
                'organization_id' => $organization->getKey(),
                'user_id' => $lockedUser->getKey(),
            ]);

            $membership->role = OrganizationRole::Owner;
            $membership->joined_at ??= now();
            $membership->save();

            if ($lockedUser->current_organization_id === null) {
                $lockedUser->current_organization_id = $organization->getKey();
                $lockedUser->save();
            }

            return $organization;
        });

        $user->refresh();

        return $organization;
    }
}
