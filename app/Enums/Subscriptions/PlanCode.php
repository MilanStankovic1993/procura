<?php

namespace App\Enums\Subscriptions;

enum PlanCode: string
{
    case Free = 'free';
    case Starter = 'starter';
    case Pro = 'pro';
    case Business = 'business';
}
