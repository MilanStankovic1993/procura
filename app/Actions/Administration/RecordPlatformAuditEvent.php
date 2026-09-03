<?php

namespace App\Actions\Administration;

use App\Models\Organization;
use App\Models\PlatformAuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class RecordPlatformAuditEvent
{
    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function record(
        User $actor,
        string $action,
        Model $subject,
        string $reason,
        ?Organization $organization = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): PlatformAuditEvent {
        return PlatformAuditEvent::query()->create([
            'actor_user_id' => $actor->getKey(),
            'organization_id' => $organization?->getKey(),
            'action' => $action,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => (string) $subject->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'reason' => trim($reason),
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 512),
        ]);
    }
}
