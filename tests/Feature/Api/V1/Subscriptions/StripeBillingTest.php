<?php

use App\Actions\Billing\ProjectStripeSubscriptionEvent;
use App\Billing\Contracts\BillingProvider;
use App\Billing\Data\BillingCheckoutResult;
use App\Enums\Organizations\OrganizationRole;
use App\Enums\Subscriptions\BillingInterval;
use App\Enums\Subscriptions\PlanCode;
use App\Models\BillingCheckoutSession;
use App\Models\BillingProviderEvent;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\OrganizationPlanAssignment;
use App\Models\Plan;
use App\Models\User;
use App\Subscriptions\SubscriptionEntitlements;
use Carbon\CarbonImmutable;
use Database\Seeders\PlanSeeder;

final class FakeStripeBillingProviderForTest implements BillingProvider
{
    public int $checkoutCalls = 0;

    public int $portalCalls = 0;

    public function createCheckout(
        Organization $organization,
        PlanCode $plan,
        BillingInterval $interval,
        string $idempotencyKey,
        string $successUrl,
        string $cancelUrl,
    ): BillingCheckoutResult {
        $this->checkoutCalls++;
        $organization->forceFill(['stripe_id' => 'cus_procura_test'])->save();

        return new BillingCheckoutResult(
            sessionId: 'cs_test_'.$this->checkoutCalls,
            customerId: 'cus_procura_test',
            priceId: "price_{$plan->value}_{$interval->value}",
            url: 'https://checkout.stripe.test/session/'.$this->checkoutCalls,
            expiresAt: CarbonImmutable::now()->addHour(),
        );
    }

    public function createPortal(
        Organization $organization,
        string $returnUrl,
    ): string {
        $this->portalCalls++;

        return 'https://billing.stripe.test/session/'.$this->portalCalls;
    }
}

beforeEach(function (): void {
    $this->seed(PlanSeeder::class);

    config([
        'billing.checkout_enabled' => true,
        'billing.prices.starter.monthly.id' => 'price_starter_monthly',
        'billing.prices.starter.monthly.amount_minor' => 1900,
        'billing.prices.starter.yearly.id' => 'price_starter_yearly',
        'billing.prices.starter.yearly.amount_minor' => 19000,
        'billing.prices.pro.monthly.id' => 'price_pro_monthly',
        'billing.prices.pro.monthly.amount_minor' => 4900,
        'billing.prices.pro.yearly.id' => 'price_pro_yearly',
        'billing.prices.pro.yearly.amount_minor' => 49000,
        'cashier.key' => 'pk_test_procura',
        'cashier.secret' => 'sk_test_procura',
        'cashier.webhook.secret' => 'whsec_procura_test',
    ]);
});

function stripeBillingWorkspace(User $user, OrganizationRole $role = OrganizationRole::Owner): Organization
{
    $organization = Organization::factory()->create();
    OrganizationMembership::factory()->create([
        'organization_id' => $organization,
        'user_id' => $user,
        'role' => $role,
    ]);
    $user->update(['current_organization_id' => $organization->getKey()]);

    return $organization;
}

/**
 * @return array<string, mixed>
 */
function stripeSubscriptionPayload(
    string $eventId,
    string $customerId,
    string $subscriptionId,
    string $type,
    string $status,
    ?string $priceId,
    int $created,
): array {
    return [
        'id' => $eventId,
        'type' => $type,
        'created' => $created,
        'livemode' => false,
        'data' => [
            'object' => [
                'id' => $subscriptionId,
                'customer' => $customerId,
                'status' => $status,
                'metadata' => ['type' => 'default'],
                'trial_end' => null,
                'items' => [
                    'data' => $priceId === null ? [] : [[
                        'id' => 'si_'.$subscriptionId,
                        'price' => [
                            'id' => $priceId,
                            'product' => 'prod_procura_test',
                        ],
                        'quantity' => 1,
                    ]],
                ],
            ],
        ],
    ];
}

test('only an owner can create one idempotent hosted checkout session', function (): void {
    $owner = User::factory()->create();
    stripeBillingWorkspace($owner);
    $provider = new FakeStripeBillingProviderForTest;
    app()->instance(BillingProvider::class, $provider);

    $payload = [
        'plan' => PlanCode::Starter->value,
        'interval' => BillingInterval::Monthly->value,
    ];
    $headers = ['Idempotency-Key' => 'checkout-request-0001'];

    $this->actingAs($owner)
        ->postJson(route('api.v1.organization.billing.checkout'), $payload, $headers)
        ->assertCreated()
        ->assertJsonPath('data.url', 'https://checkout.stripe.test/session/1');

    $this->actingAs($owner)
        ->postJson(route('api.v1.organization.billing.checkout'), $payload, $headers)
        ->assertOk()
        ->assertJsonPath('data.url', 'https://checkout.stripe.test/session/1');

    expect($provider->checkoutCalls)->toBe(1)
        ->and(BillingCheckoutSession::query()->count())->toBe(1)
        ->and(BillingCheckoutSession::query()->first()->checkout_url)
        ->toBe('https://checkout.stripe.test/session/1');
});

test('administrators members and invalid plans cannot start checkout', function (): void {
    $administrator = User::factory()->create();
    stripeBillingWorkspace($administrator, OrganizationRole::Administrator);
    $provider = new FakeStripeBillingProviderForTest;
    app()->instance(BillingProvider::class, $provider);

    $this->actingAs($administrator)
        ->postJson(route('api.v1.organization.billing.checkout'), [
            'plan' => PlanCode::Starter->value,
            'interval' => BillingInterval::Monthly->value,
        ], ['Idempotency-Key' => 'checkout-request-0002'])
        ->assertForbidden();

    expect($provider->checkoutCalls)->toBe(0);

    $owner = User::factory()->create();
    stripeBillingWorkspace($owner);

    $this->actingAs($owner)
        ->postJson(route('api.v1.organization.billing.checkout'), [
            'plan' => PlanCode::Free->value,
            'interval' => BillingInterval::Monthly->value,
        ], ['Idempotency-Key' => 'checkout-request-0003'])
        ->assertUnprocessable();
});

test('manual plan assignments cannot be overwritten by self service checkout', function (): void {
    $owner = User::factory()->create();
    $organization = stripeBillingWorkspace($owner);
    OrganizationPlanAssignment::query()->create([
        'organization_id' => $organization->getKey(),
        'plan_id' => Plan::query()->where('code', PlanCode::Pro)->valueOrFail('id'),
        'source' => 'manual',
        'starts_at' => now(),
    ]);
    $provider = new FakeStripeBillingProviderForTest;
    app()->instance(BillingProvider::class, $provider);

    $this->actingAs($owner)
        ->postJson(route('api.v1.organization.billing.checkout'), [
            'plan' => PlanCode::Starter->value,
            'interval' => BillingInterval::Monthly->value,
        ], ['Idempotency-Key' => 'checkout-request-0004'])
        ->assertConflict()
        ->assertJsonPath('code', 'billing_manual_assignment');

    expect($provider->checkoutCalls)->toBe(0);
});

test('an owner with a provider customer can open the billing portal', function (): void {
    $owner = User::factory()->create();
    $organization = stripeBillingWorkspace($owner);
    $organization->forceFill(['stripe_id' => 'cus_portal_test'])->save();
    $provider = new FakeStripeBillingProviderForTest;
    app()->instance(BillingProvider::class, $provider);

    $this->actingAs($owner)
        ->postJson(route('api.v1.organization.billing.portal'))
        ->assertOk()
        ->assertJsonPath('data.url', 'https://billing.stripe.test/session/1');

    expect($provider->portalCalls)->toBe(1);
});

test('subscription events idempotently project paid plans and reject stale cancellation', function (): void {
    $organization = stripeBillingWorkspace(User::factory()->create());
    $organization->forceFill(['stripe_id' => 'cus_projection_test'])->save();
    $projector = app(ProjectStripeSubscriptionEvent::class);
    $active = stripeSubscriptionPayload(
        eventId: 'evt_active',
        customerId: 'cus_projection_test',
        subscriptionId: 'sub_projection_test',
        type: 'customer.subscription.created',
        status: 'active',
        priceId: 'price_starter_monthly',
        created: 200,
    );

    $projector->project($active);
    $projector->project($active);

    expect(BillingProviderEvent::query()->count())->toBe(1)
        ->and($organization->fresh()->planAssignment->source)->toBe('stripe')
        ->and($organization->fresh()->planAssignment->plan->code)->toBe(PlanCode::Starter);

    $projector->project(stripeSubscriptionPayload(
        eventId: 'evt_stale_cancel',
        customerId: 'cus_projection_test',
        subscriptionId: 'sub_projection_test',
        type: 'customer.subscription.deleted',
        status: 'canceled',
        priceId: 'price_starter_monthly',
        created: 199,
    ));

    expect($organization->fresh()->planAssignment->plan->code)->toBe(PlanCode::Starter)
        ->and(BillingProviderEvent::query()
            ->where('provider_event_id', 'evt_stale_cancel')
            ->value('reason_code'))->toBe('stale_provider_event');

    $projector->project(stripeSubscriptionPayload(
        eventId: 'evt_current_cancel',
        customerId: 'cus_projection_test',
        subscriptionId: 'sub_projection_test',
        type: 'customer.subscription.deleted',
        status: 'canceled',
        priceId: 'price_starter_monthly',
        created: 201,
    ));

    expect($organization->fresh()->planAssignment)->toBeNull()
        ->and(app(SubscriptionEntitlements::class)->planFor($organization)->code)
        ->toBe(PlanCode::Free);
});

test('unknown active prices fail closed and immutable provider evidence is retained', function (): void {
    $organization = stripeBillingWorkspace(User::factory()->create());
    $organization->forceFill(['stripe_id' => 'cus_unknown_price'])->save();
    $event = app(ProjectStripeSubscriptionEvent::class)->project(
        stripeSubscriptionPayload(
            eventId: 'evt_unknown_price',
            customerId: 'cus_unknown_price',
            subscriptionId: 'sub_unknown_price',
            type: 'customer.subscription.created',
            status: 'active',
            priceId: 'price_not_mapped',
            created: 300,
        ),
    );

    expect($organization->fresh()->planAssignment)->toBeNull()
        ->and($event->outcome)->toBe('rejected')
        ->and($event->reason_code)->toBe('unknown_or_multiple_price')
        ->and(fn () => $event->update(['outcome' => 'applied']))
        ->toThrow(LogicException::class);
});

test('the stripe webhook fails closed without configuration or a valid signature', function (): void {
    config(['cashier.webhook.secret' => null]);

    $this->postJson(route('api.v1.integrations.stripe.webhook'), [])
        ->assertServiceUnavailable()
        ->assertJsonPath('code', 'stripe_webhook_not_configured');

    config(['cashier.webhook.secret' => 'whsec_procura_test']);

    $this->postJson(route('api.v1.integrations.stripe.webhook'), [
        'id' => 'evt_unsigned',
        'type' => 'customer.subscription.created',
    ])->assertForbidden();
});

test('a valid signed stripe webhook persists the subscription before projecting entitlements', function (): void {
    $organization = stripeBillingWorkspace(User::factory()->create());
    $organization->forceFill(['stripe_id' => 'cus_signed_webhook'])->save();
    $payload = stripeSubscriptionPayload(
        eventId: 'evt_signed_webhook',
        customerId: 'cus_signed_webhook',
        subscriptionId: 'sub_signed_webhook',
        type: 'customer.subscription.created',
        status: 'active',
        priceId: 'price_pro_monthly',
        created: now()->timestamp,
    );
    $json = json_encode($payload, JSON_THROW_ON_ERROR);
    $timestamp = now()->timestamp;
    $signature = hash_hmac(
        'sha256',
        $timestamp.'.'.$json,
        'whsec_procura_test',
    );

    $this->call(
        'POST',
        route('api.v1.integrations.stripe.webhook'),
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ],
        content: $json,
    )->assertOk();

    $pro = Plan::query()->where('code', PlanCode::Pro)->firstOrFail();

    $this->assertDatabaseHas('subscriptions', [
        'organization_id' => $organization->getKey(),
        'stripe_id' => 'sub_signed_webhook',
        'stripe_status' => 'active',
        'stripe_price' => 'price_pro_monthly',
    ])->assertDatabaseHas('organization_plan_assignments', [
        'organization_id' => $organization->getKey(),
        'plan_id' => $pro->getKey(),
        'source' => 'stripe',
        'provider_subscription_id' => 'sub_signed_webhook',
        'provider_status' => 'active',
    ])->assertDatabaseHas('billing_provider_events', [
        'provider_event_id' => 'evt_signed_webhook',
        'outcome' => 'applied',
        'reason_code' => 'paid_plan_projected',
    ]);
});
