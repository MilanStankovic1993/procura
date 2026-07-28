<?php

namespace App\Subscriptions;

use App\Enums\Subscriptions\FeatureCode;
use App\Models\Organization;
use App\Models\SubscriptionUsage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SubscriptionUsageService
{
    public function __construct(private readonly SubscriptionEntitlements $entitlements) {}

    public function consume(
        Organization $organization,
        FeatureCode $feature,
        int $quantity,
        string $idempotencyKey,
        ?CarbonImmutable $at = null,
    ): SubscriptionUsage {
        if (! $feature->isMetered()) {
            throw new InvalidArgumentException("The {$feature->value} feature is not a monthly usage counter.");
        }

        if ($quantity < 1 || $idempotencyKey === '' || mb_strlen($idempotencyKey) > 160) {
            throw new InvalidArgumentException('Usage quantity and idempotency key are invalid.');
        }

        $at ??= CarbonImmutable::now('UTC');
        $periodStart = $at->startOfMonth()->toDateString();
        $periodEnd = $at->endOfMonth()->toDateString();

        return DB::transaction(function () use (
            $organization, $feature, $quantity, $idempotencyKey, $periodStart, $periodEnd,
        ): SubscriptionUsage {
            DB::table('subscription_usages')->insertOrIgnore([
                'id' => (string) Str::ulid(),
                'organization_id' => $organization->getKey(),
                'feature_code' => $feature->value,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'used' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $usage = SubscriptionUsage::query()
                ->where('organization_id', $organization->getKey())
                ->where('feature_code', $feature)
                ->where('period_start', $periodStart)
                ->lockForUpdate()
                ->firstOrFail();

            $alreadyConsumed = DB::table('subscription_usage_events')
                ->where('organization_id', $organization->getKey())
                ->where('feature_code', $feature->value)
                ->where('idempotency_key', $idempotencyKey)
                ->exists();

            if ($alreadyConsumed) {
                return $usage;
            }

            $entitlement = $this->entitlements->feature($organization, $feature);
            $limit = $entitlement->is_enabled ? $entitlement->limit : 0;

            if ($limit !== null && $usage->used + $quantity > $limit) {
                throw new UsageLimitExceeded($feature, $limit, $usage->used, $quantity);
            }

            $usage->increment('used', $quantity);
            $usage->refresh();

            DB::table('subscription_usage_events')->insert([
                'id' => (string) Str::ulid(),
                'organization_id' => $organization->getKey(),
                'subscription_usage_id' => $usage->getKey(),
                'feature_code' => $feature->value,
                'idempotency_key' => $idempotencyKey,
                'quantity' => $quantity,
                'created_at' => now(),
            ]);

            return $usage;
        }, attempts: 3);
    }
}
