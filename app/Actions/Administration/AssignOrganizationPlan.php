<?php

namespace App\Actions\Administration;

use App\Models\Organization;
use App\Models\OrganizationPlanAssignment;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

class AssignOrganizationPlan
{
    public function __construct(private readonly RecordPlatformAuditEvent $audit) {}

    public function assign(
        Organization $organization,
        Plan $plan,
        User $actor,
        string $reason,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): OrganizationPlanAssignment {
        $reason = trim($reason);

        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 1000) {
            throw new InvalidArgumentException('The operational reason must contain between 10 and 1000 characters.');
        }

        return DB::transaction(function () use (
            $organization,
            $plan,
            $actor,
            $reason,
            $ipAddress,
            $userAgent,
        ): OrganizationPlanAssignment {
            $lockedActor = User::query()->lockForUpdate()->findOrFail($actor->getKey());

            if (! $lockedActor->is_super_admin || ! $lockedActor->hasVerifiedEmail()) {
                throw new AuthorizationException;
            }

            $lockedOrganization = Organization::query()->lockForUpdate()->findOrFail($organization->getKey());
            $lockedPlan = Plan::query()->lockForUpdate()->findOrFail($plan->getKey());

            if (! $lockedPlan->is_active) {
                throw new LogicException('Inactive plan versions cannot be assigned.');
            }

            $current = OrganizationPlanAssignment::query()
                ->whereKey($lockedOrganization->getKey())
                ->lockForUpdate()
                ->first();

            if ($current?->plan_id === $lockedPlan->getKey()) {
                return $current;
            }

            $assignment = OrganizationPlanAssignment::query()->updateOrCreate(
                ['organization_id' => $lockedOrganization->getKey()],
                [
                    'plan_id' => $lockedPlan->getKey(),
                    'source' => 'manual',
                    'provider' => null,
                    'provider_subscription_id' => null,
                    'provider_price_id' => null,
                    'provider_status' => null,
                    'provider_event_id' => null,
                    'provider_synced_at' => null,
                    'starts_at' => now(),
                    'ends_at' => null,
                ],
            );

            $this->audit->record(
                actor: $lockedActor,
                action: 'organization.plan_assigned',
                subject: $assignment,
                reason: $reason,
                organization: $lockedOrganization,
                oldValues: [
                    'plan_id' => $current?->plan_id,
                    'source' => $current?->source,
                ],
                newValues: [
                    'plan_id' => $lockedPlan->getKey(),
                    'plan_code' => $lockedPlan->code->value,
                    'plan_version' => $lockedPlan->version,
                    'source' => 'manual',
                ],
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $assignment;
        }, attempts: 3);
    }
}
