<?php

namespace App\Actions\Organizations;

use App\Enums\Organizations\OrganizationAuditEventType;
use App\Models\Organization;
use App\Models\OrganizationAuditEvent;
use App\Models\OrganizationInvitation;
use App\Models\User;

class RecordOrganizationAuditEvent
{
    /**
     * @param  array<string, bool|int|string|null>  $metadata
     */
    public function record(
        Organization $organization,
        OrganizationAuditEventType $event,
        ?User $actor = null,
        ?User $subjectUser = null,
        ?OrganizationInvitation $subjectInvitation = null,
        array $metadata = [],
    ): OrganizationAuditEvent {
        return OrganizationAuditEvent::query()->create([
            'organization_id' => $organization->getKey(),
            'actor_user_id' => $actor?->getKey(),
            'event' => $event,
            'subject_user_id' => $subjectUser?->getKey(),
            'subject_invitation_id' => $subjectInvitation?->getKey(),
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }
}
