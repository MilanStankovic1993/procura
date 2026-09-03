<?php

namespace App\Enums\Subscriptions;

enum BillingInterval: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';
}
