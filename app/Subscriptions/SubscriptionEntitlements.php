<?php

namespace App\Subscriptions;

use App\Enums\Subscriptions\FeatureCode;
use App\Enums\Subscriptions\PlanCode;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanFeature;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

class SubscriptionEntitlements
{
    public function planFor(Organization $organization): Plan
    {
        $now = now();
        $assignedPlanId = DB::table('organization_plan_assignments')
            ->where('organization_id', $organization->getKey())
            ->where('starts_at', '<=', $now)
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $now))
            ->value('plan_id');

        return Plan::query()
            ->with('features')
            ->when(
                $assignedPlanId,
                fn ($query) => $query->whereKey($assignedPlanId),
                fn ($query) => $query
                    ->where('code', PlanCode::Free)
                    ->where('is_active', true)
                    ->latest('version'),
            )
            ->first()
            ?? throw new LogicException('No active default subscription plan is configured.');
    }

    public function feature(Organization $organization, FeatureCode $feature): PlanFeature
    {
        return $this->planFor($organization)->features
            ->firstWhere('feature_code', $feature)
            ?? throw new LogicException("The {$feature->value} entitlement is not configured.");
    }

    /**
     * @return array{plan: Plan, features: Collection<int, PlanFeature>, period_start: CarbonImmutable, period_end: CarbonImmutable}
     */
    public function summary(Organization $organization, ?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now('UTC');
        $plan = $this->planFor($organization);

        return [
            'plan' => $plan,
            'features' => $plan->features->sortBy(fn (PlanFeature $feature) => $feature->feature_code->value)->values(),
            'period_start' => $at->startOfMonth(),
            'period_end' => $at->endOfMonth(),
        ];
    }
}
