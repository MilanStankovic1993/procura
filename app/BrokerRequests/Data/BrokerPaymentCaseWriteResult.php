<?php

namespace App\BrokerRequests\Data;

use App\Models\BrokerPaymentCase;
use App\Models\BrokerPaymentCaseEvent;

final readonly class BrokerPaymentCaseWriteResult
{
    public function __construct(
        public BrokerPaymentCase $paymentCase,
        public BrokerPaymentCaseEvent $event,
        public bool $created,
    ) {}
}
