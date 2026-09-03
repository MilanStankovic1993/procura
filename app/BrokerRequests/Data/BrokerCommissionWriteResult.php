<?php

namespace App\BrokerRequests\Data;

use App\Models\BrokerCommission;
use App\Models\BrokerCommissionEvent;

final readonly class BrokerCommissionWriteResult
{
    public function __construct(
        public BrokerCommission $commission,
        public BrokerCommissionEvent $event,
        public bool $created,
    ) {}
}
