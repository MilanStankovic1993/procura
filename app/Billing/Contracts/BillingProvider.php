<?php

namespace App\Billing\Contracts;

use App\Billing\Data\BillingCheckoutResult;
use App\Enums\Subscriptions\BillingInterval;
use App\Enums\Subscriptions\PlanCode;
use App\Models\Organization;

interface BillingProvider
{
    public function createCheckout(
        Organization $organization,
        PlanCode $plan,
        BillingInterval $interval,
        string $idempotencyKey,
        string $successUrl,
        string $cancelUrl,
    ): BillingCheckoutResult;

    public function createPortal(
        Organization $organization,
        string $returnUrl,
    ): string;
}
