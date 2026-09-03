<?php

namespace App\BrokerRequests\Data;

use App\Models\BrokerCommission;
use App\Models\BrokerCommissionEvent;
use App\Models\BrokerRequest;
use App\Models\BrokerRequestEvent;
use App\Models\BrokerTransaction;
use App\Models\BrokerTransactionEvent;

final readonly class BrokerTransactionWriteResult
{
    public function __construct(
        public BrokerTransaction $transaction,
        public BrokerTransactionEvent $transactionEvent,
        public BrokerCommission $commission,
        public ?BrokerCommissionEvent $commissionEvent,
        public ?BrokerRequest $brokerRequest,
        public ?BrokerRequestEvent $requestEvent,
        public bool $created,
    ) {}
}
