<?php

namespace App\BrokerRequests\Data;

use App\Models\BrokerRequest;
use App\Models\BrokerRequestEvent;

final readonly class BrokerRequestWriteResult
{
    public function __construct(
        public BrokerRequest $brokerRequest,
        public BrokerRequestEvent $event,
        public bool $created,
    ) {}
}
