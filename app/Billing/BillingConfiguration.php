<?php

namespace App\Billing;

use App\Enums\Subscriptions\BillingInterval;
use App\Enums\Subscriptions\PlanCode;
use LogicException;
use Symfony\Component\Intl\Currencies;

class BillingConfiguration
{
    public function provider(): string
    {
        return (string) config('billing.provider');
    }

    public function checkoutEnabled(): bool
    {
        return (bool) config('billing.checkout_enabled');
    }

    public function webhookReady(): bool
    {
        return $this->provider() === 'stripe'
            && $this->configuredString(config('cashier.webhook.secret')) !== null;
    }

    public function providerReady(): bool
    {
        return $this->provider() === 'stripe'
            && $this->configuredString(config('cashier.key')) !== null
            && $this->configuredString(config('cashier.secret')) !== null
            && $this->webhookReady();
    }

    public function checkoutAvailable(
        PlanCode $plan,
        BillingInterval $interval,
    ): bool {
        return $this->checkoutEnabled()
            && $this->providerReady()
            && $this->priceId($plan, $interval) !== null
            && $this->amountMinor($plan, $interval) > 0;
    }

    public function priceId(
        PlanCode $plan,
        BillingInterval $interval,
    ): ?string {
        return $this->configuredString(
            config("billing.prices.{$plan->value}.{$interval->value}.id"),
        );
    }

    public function amountMinor(
        PlanCode $plan,
        BillingInterval $interval,
    ): int {
        return max(
            0,
            (int) config(
                "billing.prices.{$plan->value}.{$interval->value}.amount_minor",
                0,
            ),
        );
    }

    public function currency(): string
    {
        $currency = strtolower((string) config('billing.currency', 'eur'));

        if (
            preg_match('/^[a-z]{3}$/', $currency) !== 1
            || ! Currencies::exists(strtoupper($currency))
        ) {
            throw new LogicException('The billing currency must be an ISO 4217 currency code.');
        }

        return $currency;
    }

    public function planForPrice(?string $priceId): ?PlanCode
    {
        if ($priceId === null || trim($priceId) === '') {
            return null;
        }

        $matches = [];

        foreach ([PlanCode::Starter, PlanCode::Pro] as $plan) {
            foreach (BillingInterval::cases() as $interval) {
                if (hash_equals((string) $this->priceId($plan, $interval), $priceId)) {
                    $matches[] = $plan;
                }
            }
        }

        if (count($matches) > 1) {
            throw new LogicException('A Stripe price identifier is mapped to multiple Procura plans.');
        }

        return $matches[0] ?? null;
    }

    /**
     * @return list<array{plan: string, interval: string, amount_minor: int, currency: string, currency_minor_unit: int, available: bool}>
     */
    public function publicOffers(): array
    {
        $offers = [];

        foreach ([PlanCode::Starter, PlanCode::Pro] as $plan) {
            foreach (BillingInterval::cases() as $interval) {
                $offers[] = [
                    'plan' => $plan->value,
                    'interval' => $interval->value,
                    'amount_minor' => $this->amountMinor($plan, $interval),
                    'currency' => $this->currency(),
                    'currency_minor_unit' => Currencies::getFractionDigits(
                        strtoupper($this->currency()),
                    ),
                    'available' => $this->checkoutAvailable($plan, $interval),
                ];
            }
        }

        return $offers;
    }

    /**
     * @return list<string>
     */
    public function entitledStatuses(): array
    {
        return array_values(array_filter(
            config('billing.entitled_statuses', []),
            static fn (mixed $status): bool => is_string($status) && $status !== '',
        ));
    }

    private function configuredString(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
