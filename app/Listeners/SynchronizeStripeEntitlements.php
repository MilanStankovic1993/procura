<?php

namespace App\Listeners;

use App\Actions\Billing\ProjectStripeSubscriptionEvent;
use Laravel\Cashier\Events\WebhookHandled;

class SynchronizeStripeEntitlements
{
    public function __construct(
        private readonly ProjectStripeSubscriptionEvent $projector,
    ) {}

    public function handle(WebhookHandled $event): void
    {
        $this->projector->project($event->payload);
    }
}
