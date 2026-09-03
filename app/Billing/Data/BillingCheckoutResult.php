<?php

namespace App\Billing\Data;

use Carbon\CarbonImmutable;

final readonly class BillingCheckoutResult
{
    public function __construct(
        public string $sessionId,
        public string $customerId,
        public string $priceId,
        public string $url,
        public CarbonImmutable $expiresAt,
    ) {}
}
