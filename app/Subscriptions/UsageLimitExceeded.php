<?php

namespace App\Subscriptions;

use App\Enums\Subscriptions\FeatureCode;
use RuntimeException;

class UsageLimitExceeded extends RuntimeException
{
    public function __construct(
        public readonly FeatureCode $feature,
        public readonly int $limit,
        public readonly int $used,
        public readonly int $requested,
    ) {
        parent::__construct("The {$feature->value} usage limit has been reached.");
    }
}
