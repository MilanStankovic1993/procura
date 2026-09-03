<?php

namespace App\Actions\Billing;

use App\Billing\Contracts\BillingProvider;
use App\Enums\Api\ApiErrorCode;
use App\Enums\Subscriptions\BillingInterval;
use App\Enums\Subscriptions\PlanCode;
use App\Exceptions\BillingException;
use App\Models\BillingCheckoutSession;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

class StartBillingCheckout
{
    public function __construct(
        private readonly BillingProvider $provider,
    ) {}

    public function start(
        Organization $organization,
        User $actor,
        PlanCode $plan,
        BillingInterval $interval,
        string $idempotencyKey,
        string $successUrl,
        string $cancelUrl,
    ): BillingCheckoutSession {
        $idempotencyHash = hash('sha256', $idempotencyKey);
        $lock = Cache::lock(
            'billing-checkout:organization:'.$organization->getKey(),
            30,
        );

        try {
            return $lock->block(5, function () use (
                $organization,
                $actor,
                $plan,
                $interval,
                $idempotencyKey,
                $idempotencyHash,
                $successUrl,
                $cancelUrl,
            ): BillingCheckoutSession {
                $existing = BillingCheckoutSession::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('idempotency_hash', $idempotencyHash)
                    ->first();

                if ($existing !== null) {
                    if ($existing->expires_at->isPast()) {
                        throw new BillingException(
                            'This Checkout request has expired. Start again with a new idempotency key.',
                            ApiErrorCode::BillingCheckoutExpired,
                            409,
                        );
                    }

                    return $existing;
                }

                $manualAssignment = $organization->planAssignment()
                    ->where('source', 'manual')
                    ->exists();

                if ($manualAssignment) {
                    throw new BillingException(
                        'This workspace has an administrator-managed plan assignment.',
                        ApiErrorCode::BillingManualAssignment,
                        409,
                    );
                }

                $hasProviderSubscription = $organization->subscriptions()
                    ->where('type', 'default')
                    ->whereNotIn('stripe_status', ['canceled', 'incomplete_expired'])
                    ->where(function ($query): void {
                        $query->whereNull('ends_at')->orWhere('ends_at', '>', now());
                    })
                    ->exists();

                if ($hasProviderSubscription) {
                    throw new BillingException(
                        'Manage the existing subscription through the billing portal.',
                        ApiErrorCode::BillingSubscriptionExists,
                        409,
                    );
                }

                $result = $this->provider->createCheckout(
                    organization: $organization,
                    plan: $plan,
                    interval: $interval,
                    idempotencyKey: $idempotencyKey,
                    successUrl: $successUrl,
                    cancelUrl: $cancelUrl,
                );

                return BillingCheckoutSession::query()->create([
                    'organization_id' => $organization->getKey(),
                    'actor_user_id' => $actor->getKey(),
                    'provider' => 'stripe',
                    'idempotency_hash' => $idempotencyHash,
                    'plan_code' => $plan,
                    'billing_interval' => $interval,
                    'provider_session_id' => $result->sessionId,
                    'provider_customer_id' => $result->customerId,
                    'provider_price_id' => $result->priceId,
                    'checkout_url' => $result->url,
                    'status' => 'open',
                    'expires_at' => $result->expiresAt,
                ]);
            });
        } catch (LockTimeoutException) {
            throw new BillingException(
                'Another billing operation is already in progress for this workspace.',
                ApiErrorCode::BillingOperationInProgress,
                409,
            );
        }
    }
}
