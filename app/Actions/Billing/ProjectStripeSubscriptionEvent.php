<?php

namespace App\Actions\Billing;

use App\Billing\BillingConfiguration;
use App\Enums\Subscriptions\PlanCode;
use App\Models\BillingProviderEvent;
use App\Models\Organization;
use App\Models\OrganizationPlanAssignment;
use App\Models\Plan;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use JsonException;
use UnexpectedValueException;

class ProjectStripeSubscriptionEvent
{
    private const SUPPORTED_EVENTS = [
        'customer.subscription.created',
        'customer.subscription.updated',
        'customer.subscription.deleted',
    ];

    public function __construct(
        private readonly BillingConfiguration $configuration,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws JsonException
     */
    public function project(array $payload): ?BillingProviderEvent
    {
        $type = $this->requiredString($payload, 'type');

        if (! in_array($type, self::SUPPORTED_EVENTS, true)) {
            return null;
        }

        $providerEventId = $this->requiredString($payload, 'id');
        $customerId = $this->requiredString($payload, 'data.object.customer');
        $subscriptionId = $this->requiredString($payload, 'data.object.id');
        $occurredAt = CarbonImmutable::createFromTimestampUTC(
            (int) Arr::get($payload, 'created', 0),
        );
        $status = $type === 'customer.subscription.deleted'
            ? 'canceled'
            : $this->requiredString($payload, 'data.object.status');
        $priceId = $this->singlePriceId($payload);
        $payloadHash = hash(
            'sha256',
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );

        try {
            return DB::transaction(function () use (
                $providerEventId,
                $type,
                $customerId,
                $subscriptionId,
                $occurredAt,
                $status,
                $priceId,
                $payloadHash,
                $payload,
            ): BillingProviderEvent {
                $duplicate = BillingProviderEvent::query()
                    ->where('provider', 'stripe')
                    ->where('provider_event_id', $providerEventId)
                    ->first();

                if ($duplicate !== null) {
                    return $duplicate;
                }

                $organization = Organization::query()
                    ->where('stripe_id', $customerId)
                    ->lockForUpdate()
                    ->first();

                if ($organization === null) {
                    return $this->record(
                        providerEventId: $providerEventId,
                        type: $type,
                        payloadHash: $payloadHash,
                        livemode: (bool) Arr::get($payload, 'livemode', false),
                        occurredAt: $occurredAt,
                        customerId: $customerId,
                        subscriptionId: $subscriptionId,
                        priceId: $priceId,
                        status: $status,
                        outcome: 'ignored',
                        reasonCode: 'organization_not_found',
                    );
                }

                $latest = BillingProviderEvent::query()
                    ->where('provider', 'stripe')
                    ->where('provider_subscription_id', $subscriptionId)
                    ->orderByDesc('occurred_at')
                    ->orderByDesc('created_at')
                    ->first();

                if ($latest !== null && $this->isStale($type, $occurredAt, $latest)) {
                    return $this->record(
                        providerEventId: $providerEventId,
                        type: $type,
                        payloadHash: $payloadHash,
                        livemode: (bool) Arr::get($payload, 'livemode', false),
                        occurredAt: $occurredAt,
                        organization: $organization,
                        customerId: $customerId,
                        subscriptionId: $subscriptionId,
                        priceId: $priceId,
                        status: $status,
                        outcome: 'ignored',
                        reasonCode: 'stale_provider_event',
                    );
                }

                $assignment = OrganizationPlanAssignment::query()
                    ->whereKey($organization->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($assignment !== null && $assignment->source !== 'stripe') {
                    return $this->record(
                        providerEventId: $providerEventId,
                        type: $type,
                        payloadHash: $payloadHash,
                        livemode: (bool) Arr::get($payload, 'livemode', false),
                        occurredAt: $occurredAt,
                        organization: $organization,
                        customerId: $customerId,
                        subscriptionId: $subscriptionId,
                        priceId: $priceId,
                        status: $status,
                        outcome: 'ignored',
                        reasonCode: 'manual_assignment_present',
                    );
                }

                if (
                    $assignment !== null
                    && $assignment->provider_subscription_id !== null
                    && $assignment->provider_subscription_id !== $subscriptionId
                    && in_array(
                        $assignment->provider_status,
                        $this->configuration->entitledStatuses(),
                        true,
                    )
                ) {
                    return $this->record(
                        providerEventId: $providerEventId,
                        type: $type,
                        payloadHash: $payloadHash,
                        livemode: (bool) Arr::get($payload, 'livemode', false),
                        occurredAt: $occurredAt,
                        organization: $organization,
                        customerId: $customerId,
                        subscriptionId: $subscriptionId,
                        priceId: $priceId,
                        status: $status,
                        outcome: 'ignored',
                        reasonCode: 'active_subscription_conflict',
                    );
                }

                if (! in_array($status, $this->configuration->entitledStatuses(), true)) {
                    if (
                        $assignment !== null
                        && $assignment->source === 'stripe'
                        && $assignment->provider_subscription_id === $subscriptionId
                    ) {
                        $assignment->delete();
                    }

                    return $this->record(
                        providerEventId: $providerEventId,
                        type: $type,
                        payloadHash: $payloadHash,
                        livemode: (bool) Arr::get($payload, 'livemode', false),
                        occurredAt: $occurredAt,
                        organization: $organization,
                        customerId: $customerId,
                        subscriptionId: $subscriptionId,
                        priceId: $priceId,
                        status: $status,
                        outcome: 'applied',
                        reasonCode: 'free_fallback_for_non_entitled_status',
                        projectedPlan: PlanCode::Free,
                    );
                }

                $planCode = $this->configuration->planForPrice($priceId);

                if ($planCode === null) {
                    if (
                        $assignment !== null
                        && $assignment->source === 'stripe'
                        && $assignment->provider_subscription_id === $subscriptionId
                    ) {
                        $assignment->delete();
                    }

                    return $this->record(
                        providerEventId: $providerEventId,
                        type: $type,
                        payloadHash: $payloadHash,
                        livemode: (bool) Arr::get($payload, 'livemode', false),
                        occurredAt: $occurredAt,
                        organization: $organization,
                        customerId: $customerId,
                        subscriptionId: $subscriptionId,
                        priceId: $priceId,
                        status: $status,
                        outcome: 'rejected',
                        reasonCode: 'unknown_or_multiple_price',
                        projectedPlan: PlanCode::Free,
                    );
                }

                $plan = Plan::query()
                    ->where('code', $planCode)
                    ->where('is_active', true)
                    ->latest('version')
                    ->lockForUpdate()
                    ->first();

                if ($plan === null) {
                    return $this->record(
                        providerEventId: $providerEventId,
                        type: $type,
                        payloadHash: $payloadHash,
                        livemode: (bool) Arr::get($payload, 'livemode', false),
                        occurredAt: $occurredAt,
                        organization: $organization,
                        customerId: $customerId,
                        subscriptionId: $subscriptionId,
                        priceId: $priceId,
                        status: $status,
                        outcome: 'rejected',
                        reasonCode: 'active_plan_version_missing',
                    );
                }

                $assignment ??= new OrganizationPlanAssignment([
                    'organization_id' => $organization->getKey(),
                ]);
                $assignment->fill([
                    'plan_id' => $plan->getKey(),
                    'source' => 'stripe',
                    'provider' => 'stripe',
                    'provider_subscription_id' => $subscriptionId,
                    'provider_price_id' => $priceId,
                    'provider_status' => $status,
                    'provider_event_id' => $providerEventId,
                    'provider_synced_at' => $occurredAt,
                    'starts_at' => $assignment->starts_at ?? $occurredAt,
                    'ends_at' => null,
                ])->save();

                return $this->record(
                    providerEventId: $providerEventId,
                    type: $type,
                    payloadHash: $payloadHash,
                    livemode: (bool) Arr::get($payload, 'livemode', false),
                    occurredAt: $occurredAt,
                    organization: $organization,
                    customerId: $customerId,
                    subscriptionId: $subscriptionId,
                    priceId: $priceId,
                    status: $status,
                    outcome: 'applied',
                    reasonCode: 'paid_plan_projected',
                    projectedPlan: $planCode,
                );
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            return BillingProviderEvent::query()
                ->where('provider', 'stripe')
                ->where('provider_event_id', $providerEventId)
                ->first()
                ?? throw $exception;
        }
    }

    private function isStale(
        string $incomingType,
        CarbonImmutable $occurredAt,
        BillingProviderEvent $latest,
    ): bool {
        if ($occurredAt->lt($latest->occurred_at)) {
            return true;
        }

        if (! $occurredAt->equalTo($latest->occurred_at)) {
            return false;
        }

        return $this->eventPrecedence($incomingType) <= $this->eventPrecedence(
            $latest->event_type,
        );
    }

    private function eventPrecedence(string $type): int
    {
        return match ($type) {
            'customer.subscription.deleted' => 30,
            'customer.subscription.updated' => 20,
            'customer.subscription.created' => 10,
            default => 0,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function singlePriceId(array $payload): ?string
    {
        $items = Arr::get($payload, 'data.object.items.data');

        if (! is_array($items) || count($items) !== 1) {
            return null;
        }

        $priceId = Arr::get($items[0], 'price.id');

        return is_string($priceId) && $priceId !== '' ? $priceId : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requiredString(array $payload, string $path): string
    {
        $value = Arr::get($payload, $path);

        if (! is_string($value) || trim($value) === '') {
            throw new UnexpectedValueException("Stripe webhook field [{$path}] is required.");
        }

        return trim($value);
    }

    private function record(
        string $providerEventId,
        string $type,
        string $payloadHash,
        bool $livemode,
        CarbonImmutable $occurredAt,
        ?Organization $organization = null,
        ?string $customerId = null,
        ?string $subscriptionId = null,
        ?string $priceId = null,
        ?string $status = null,
        string $outcome = 'ignored',
        string $reasonCode = 'not_applicable',
        ?PlanCode $projectedPlan = null,
    ): BillingProviderEvent {
        return BillingProviderEvent::query()->create([
            'provider' => 'stripe',
            'provider_event_id' => $providerEventId,
            'organization_id' => $organization?->getKey(),
            'event_type' => $type,
            'provider_customer_id' => $customerId,
            'provider_subscription_id' => $subscriptionId,
            'provider_price_id' => $priceId,
            'provider_status' => $status,
            'payload_sha256' => $payloadHash,
            'livemode' => $livemode,
            'outcome' => $outcome,
            'reason_code' => $reasonCode,
            'projected_plan_code' => $projectedPlan?->value,
            'occurred_at' => $occurredAt,
            'processed_at' => now(),
        ]);
    }
}
