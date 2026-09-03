<?php

namespace App\Actions\Organizations;

use App\Enums\Organizations\OrganizationAuditEventType;
use App\Enums\Organizations\OrganizationPermission;
use App\Enums\Organizations\OrganizationType;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class UpdateOrganization
{
    public function __construct(
        private readonly RecordOrganizationAuditEvent $recordAuditEvent,
    ) {}

    public function update(Organization $organization, User $actor, string $name): Organization
    {
        return DB::transaction(function () use ($organization, $actor, $name): Organization {
            $lockedOrganization = Organization::query()
                ->lockForUpdate()
                ->findOrFail($organization->getKey());

            $membership = OrganizationMembership::query()
                ->where('organization_id', $lockedOrganization->getKey())
                ->where('user_id', $actor->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $lockedOrganization->type !== OrganizationType::Business
                || ! $membership->role->allows(OrganizationPermission::UpdateOrganization)
            ) {
                throw new AuthorizationException;
            }

            $newName = trim($name);
            $previousName = $lockedOrganization->name;

            if ($previousName === $newName) {
                return $lockedOrganization;
            }

            $lockedOrganization->name = $newName;
            $lockedOrganization->save();

            $this->recordAuditEvent->record(
                organization: $lockedOrganization,
                event: OrganizationAuditEventType::OrganizationRenamed,
                actor: $actor,
                metadata: [
                    'previous_name' => $previousName,
                    'new_name' => $newName,
                ],
            );

            return $lockedOrganization;
        });
    }
}
