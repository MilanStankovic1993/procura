<?php

namespace App\Actions\Billing;

use App\Billing\Contracts\BillingProvider;
use App\Models\Organization;

class OpenBillingPortal
{
    public function __construct(
        private readonly BillingProvider $provider,
    ) {}

    public function url(Organization $organization, string $returnUrl): string
    {
        return $this->provider->createPortal($organization, $returnUrl);
    }
}
