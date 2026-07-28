<?php

use App\Enums\Subscriptions\BillingInterval;
use App\Enums\Subscriptions\PlanCode;

return [
    'provider' => env('BILLING_PROVIDER', 'stripe'),
    'checkout_enabled' => (bool) env('BILLING_CHECKOUT_ENABLED', false),
    'allow_promotion_codes' => (bool) env('BILLING_ALLOW_PROMOTION_CODES', false),
    'collect_tax_ids' => (bool) env('BILLING_COLLECT_TAX_IDS', true),
    'currency' => strtolower((string) env('CASHIER_CURRENCY', 'eur')),
    'currency_locale' => env('CASHIER_CURRENCY_LOCALE', 'en_IE'),
    'entitled_statuses' => ['active', 'trialing'],
    'prices' => [
        PlanCode::Starter->value => [
            BillingInterval::Monthly->value => [
                'id' => env('STRIPE_PRICE_STARTER_MONTHLY'),
                'amount_minor' => (int) env('BILLING_PRICE_STARTER_MONTHLY_MINOR', 0),
            ],
            BillingInterval::Yearly->value => [
                'id' => env('STRIPE_PRICE_STARTER_YEARLY'),
                'amount_minor' => (int) env('BILLING_PRICE_STARTER_YEARLY_MINOR', 0),
            ],
        ],
        PlanCode::Pro->value => [
            BillingInterval::Monthly->value => [
                'id' => env('STRIPE_PRICE_PRO_MONTHLY'),
                'amount_minor' => (int) env('BILLING_PRICE_PRO_MONTHLY_MINOR', 0),
            ],
            BillingInterval::Yearly->value => [
                'id' => env('STRIPE_PRICE_PRO_YEARLY'),
                'amount_minor' => (int) env('BILLING_PRICE_PRO_YEARLY_MINOR', 0),
            ],
        ],
    ],
];
