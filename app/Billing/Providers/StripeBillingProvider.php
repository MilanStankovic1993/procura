<?php

namespace App\Billing\Providers;

use App\Billing\BillingConfiguration;
use App\Billing\Contracts\BillingProvider;
use App\Billing\Data\BillingCheckoutResult;
use App\Enums\Api\ApiErrorCode;
use App\Enums\Subscriptions\BillingInterval;
use App\Enums\Subscriptions\PlanCode;
use App\Exceptions\BillingException;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Laravel\Cashier\Cashier;
use Stripe\Exception\ApiErrorException;

class StripeBillingProvider implements BillingProvider
{
    public function __construct(
        private readonly BillingConfiguration $configuration,
    ) {}

    public function createCheckout(
        Organization $organization,
        PlanCode $plan,
        BillingInterval $interval,
        string $idempotencyKey,
        string $successUrl,
        string $cancelUrl,
    ): BillingCheckoutResult {
        $priceId = $this->configuration->priceId($plan, $interval);

        if (! $this->configuration->checkoutAvailable($plan, $interval) || $priceId === null) {
            throw new BillingException(
                'Billing Checkout is not configured for this plan and interval.',
                ApiErrorCode::BillingNotConfigured,
                503,
            );
        }

        try {
            $customer = $organization->hasStripeId()
                ? $organization->asStripeCustomer()
                : $organization->createAsStripeCustomer(
                    [
                        'metadata' => [
                            'procura_organization_id' => (string) $organization->getKey(),
                        ],
                    ],
                    [
                        'idempotency_key' => 'procura-customer-'.$organization->getKey(),
                    ],
                );

            $session = Cashier::stripe()->checkout->sessions->create([
                'customer' => $customer->id,
                'client_reference_id' => (string) $organization->getKey(),
                'mode' => 'subscription',
                'line_items' => [[
                    'price' => $priceId,
                    'quantity' => 1,
                ]],
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'allow_promotion_codes' => (bool) config('billing.allow_promotion_codes'),
                'billing_address_collection' => 'required',
                'tax_id_collection' => [
                    'enabled' => (bool) config('billing.collect_tax_ids'),
                ],
                'metadata' => [
                    'procura_organization_id' => (string) $organization->getKey(),
                    'procura_plan_code' => $plan->value,
                    'procura_billing_interval' => $interval->value,
                ],
                'subscription_data' => [
                    'metadata' => [
                        'type' => 'default',
                        'procura_organization_id' => (string) $organization->getKey(),
                        'procura_plan_code' => $plan->value,
                    ],
                ],
            ], [
                'idempotency_key' => 'procura-checkout-'.hash(
                    'sha256',
                    $organization->getKey().'|'.$idempotencyKey,
                ),
            ]);
        } catch (ApiErrorException $exception) {
            report($exception);

            throw new BillingException(
                'The billing provider could not create a Checkout session.',
                ApiErrorCode::BillingProviderUnavailable,
                502,
            );
        }

        $url = is_string($session->url) ? $session->url : '';

        if (
            $url === ''
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https'
        ) {
            throw new BillingException(
                'The billing provider returned an invalid Checkout destination.',
                ApiErrorCode::BillingProviderInvalidResponse,
                502,
            );
        }

        return new BillingCheckoutResult(
            sessionId: (string) $session->id,
            customerId: (string) $customer->id,
            priceId: $priceId,
            url: $url,
            expiresAt: CarbonImmutable::createFromTimestampUTC((int) $session->expires_at),
        );
    }

    public function createPortal(
        Organization $organization,
        string $returnUrl,
    ): string {
        if (! $this->configuration->providerReady() || ! $organization->hasStripeId()) {
            throw new BillingException(
                'The billing portal is not available for this workspace.',
                ApiErrorCode::BillingPortalUnavailable,
                409,
            );
        }

        try {
            $url = $organization->billingPortalUrl($returnUrl);
        } catch (ApiErrorException $exception) {
            report($exception);

            throw new BillingException(
                'The billing provider could not create a portal session.',
                ApiErrorCode::BillingProviderUnavailable,
                502,
            );
        }

        if (
            filter_var($url, FILTER_VALIDATE_URL) === false
            || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https'
        ) {
            throw new BillingException(
                'The billing provider returned an invalid portal destination.',
                ApiErrorCode::BillingProviderInvalidResponse,
                502,
            );
        }

        return $url;
    }
}
