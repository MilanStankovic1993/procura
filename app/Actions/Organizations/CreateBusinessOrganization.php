<?php

namespace App\Actions\Organizations;

use App\Enums\Organizations\OrganizationAuditEventType;
use App\Enums\Organizations\OrganizationRole;
use App\Enums\Organizations\OrganizationType;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateBusinessOrganization
{
    public function __construct(
        private readonly RecordOrganizationAuditEvent $recordAuditEvent,
    ) {}

    public function create(User $user, string $name): OrganizationMembership
    {
        $membership = DB::transaction(function () use ($user, $name): OrganizationMembership {
            $lockedUser = User::query()
                ->lockForUpdate()
                ->findOrFail($user->getKey());

            $organization = Organization::query()->create([
                'name' => trim($name),
                'type' => OrganizationType::Business,
            ]);

            $membership = OrganizationMembership::query()->create([
                'organization_id' => $organization->getKey(),
                'user_id' => $lockedUser->getKey(),
                'role' => OrganizationRole::Owner,
                'joined_at' => now(),
            ]);

            $lockedUser->current_organization_id = $organization->getKey();
            $lockedUser->save();

            $this->recordAuditEvent->record(
                organization: $organization,
                event: OrganizationAuditEventType::OrganizationCreated,
                actor: $lockedUser,
            );

            return $membership->setRelation('organization', $organization);
        });

        $user->current_organization_id = $membership->organization_id;

        return $membership;
    }
}
