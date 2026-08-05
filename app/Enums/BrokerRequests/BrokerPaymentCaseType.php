<?php

namespace App\Enums\BrokerRequests;

enum BrokerPaymentCaseType: string
{
    case Refund = 'refund';
    case Dispute = 'dispute';
}
