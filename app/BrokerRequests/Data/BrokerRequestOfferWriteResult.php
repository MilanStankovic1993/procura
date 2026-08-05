<?php

namespace App\BrokerRequests\Data;

use App\Models\BrokerCommission;
use App\Models\BrokerRequest;
use App\Models\BrokerRequestEvent;
use App\Models\BrokerRequestOffer;
use App\Models\BrokerRequestOfferEvent;
use App\Models\BrokerTransaction;

final readonly class BrokerRequestOfferWriteResult
{
    public function __construct(
        public BrokerRequest $brokerRequest,
        public BrokerRequestOffer $offer,
        public BrokerRequestEvent $requestEvent,
        public BrokerRequestOfferEvent $offerEvent,
        public bool $created,
        public ?BrokerTransaction $transaction = null,
        public ?BrokerCommission $commission = null,
    ) {}
}
