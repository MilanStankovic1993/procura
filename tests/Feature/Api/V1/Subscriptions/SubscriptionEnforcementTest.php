<?php

use App\Enums\Subscriptions\FeatureCode;
use App\Enums\Subscriptions\PlanCode;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Plan;
use App\Models\SubscriptionUsage;
use App\Models\User;
use App\Subscriptions\SubscriptionUsageService;
use App\Subscriptions\UsageLimitExceeded;
use Carbon\CarbonImmutable;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(PlanSeeder::class);
});

function subscriptionOrganization(User $user): Organization
{
    $organization = Organization::factory()->create();
    OrganizationMembership::factory()->owner()->create([
        'organization_id' => $organization,
        'user_id' => $user,
    ]);
    $user->update(['current_organization_id' => $organization->getKey()]);

    return $organization;
}

function assignSubscriptionPlan(Organization $organization, PlanCode $code): void
{
    DB::table('organization_plan_assignments')->updateOrInsert(
        ['organization_id' => $organization->getKey()],
        [
            'plan_id' => Plan::query()->where('code', $code)->where('is_active', true)->valueOrFail('id'),
            'starts_at' => now()->subMinute(),
            'ends_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    );
}

test('the active tenant receives backend authoritative plan entitlements and usage', function () {
    $user = User::factory()->create();
    $organization = subscriptionOrganization($user);

    app(SubscriptionUsageService::class)->consume(
        $organization,
        FeatureCode::MonthlyAnalyses,
        2,
        'analysis:01',
    );

    $this->actingAs($user)
        ->getJson(route('api.v1.organization.subscription.show'))
        ->assertOk()
        ->assertJsonPath('data.plan.code', 'free')
        ->assertJsonPath('data.plan.version', 1)
        ->assertJsonPath('data.features.0.code', 'analyses.monthly')
        ->assertJsonPath('data.features.0.limit', 5)
        ->assertJsonPath('data.features.0.used', 2);
});

test('usage checks are atomic at the limit and duplicate consumption is idempotent', function () {
    $organization = subscriptionOrganization(User::factory()->create());
    $usage = app(SubscriptionUsageService::class);

    $usage->consume($organization, FeatureCode::MonthlyAnalyses, 4, 'job:one');
    $usage->consume($organization, FeatureCode::MonthlyAnalyses, 4, 'job:one');
    $final = $usage->consume($organization, FeatureCode::MonthlyAnalyses, 1, 'job:two');

    expect($final->used)->toBe(5)
        ->and(DB::table('subscription_usage_events')->count())->toBe(2);

    expect(fn () => $usage->consume(
        $organization,
        FeatureCode::MonthlyAnalyses,
        1,
        'job:three',
    ))->toThrow(UsageLimitExceeded::class);

    expect(DB::table('subscription_usages')->value('used'))->toBe(5)
        ->and(DB::table('subscription_usage_events')->count())->toBe(2);
});

test('monthly usage rolls over without mutating the historical period', function () {
    $organization = subscriptionOrganization(User::factory()->create());
    $usage = app(SubscriptionUsageService::class);

    $january = $usage->consume(
        $organization,
        FeatureCode::MonthlyAnalyses,
        5,
        'jan:batch',
        CarbonImmutable::parse('2026-01-31 23:59:59', 'UTC'),
    );
    $february = $usage->consume(
        $organization,
        FeatureCode::MonthlyAnalyses,
        1,
        'feb:batch',
        CarbonImmutable::parse('2026-02-01 00:00:00', 'UTC'),
    );

    expect($january->used)->toBe(5)
        ->and($february->used)->toBe(1)
        ->and(SubscriptionUsage::query()->count())->toBe(2);
});

test('unlimited business usage and active tenant isolation are enforced', function () {
    $user = User::factory()->create();
    $freeOrganization = subscriptionOrganization($user);
    $businessOrganization = Organization::factory()->create();
    OrganizationMembership::factory()->owner()->create([
        'organization_id' => $businessOrganization,
        'user_id' => $user,
    ]);
    assignSubscriptionPlan($businessOrganization, PlanCode::Business);

    $usage = app(SubscriptionUsageService::class);
    expect($usage->consume(
        $businessOrganization,
        FeatureCode::MonthlyAnalyses,
        100000,
        'business:bulk',
    )->used)->toBe(100000);

    $this->actingAs($user)
        ->getJson(route('api.v1.organization.subscription.show'))
        ->assertOk()
        ->assertJsonPath('data.plan.code', 'free')
        ->assertJsonMissing(['used' => 100000]);

    $this->actingAs($user)
        ->putJson(route('api.v1.organizations.activate', $businessOrganization))
        ->assertOk();

    $this->actingAs($user->fresh())
        ->getJson(route('api.v1.organization.subscription.show'))
        ->assertOk()
        ->assertJsonPath('data.plan.code', 'business')
        ->assertJsonPath('data.features.0.limit', null)
        ->assertJsonPath('data.features.0.used', 100000);

    expect($freeOrganization->is($businessOrganization))->toBeFalse();
});

test('plan seeding is idempotent and retains one complete version of every documented plan', function () {
    $this->seed(PlanSeeder::class);

    expect(Plan::query()->count())->toBe(4)
        ->and(DB::table('plan_features')->count())->toBe(4 * count(FeatureCode::cases()))
        ->and(Plan::query()->orderBy('sort_order')->get()->map(fn (Plan $plan) => $plan->code->value)->all())
        ->toBe(['free', 'starter', 'pro', 'business']);
});
