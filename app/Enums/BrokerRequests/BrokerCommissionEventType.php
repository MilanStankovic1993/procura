<?php

namespace App\Enums\BrokerRequests;

enum BrokerCommissionEventType: string
{
    case Recorded = 'recorded';
    case Earned = 'earned';
    case Settled = 'settled';
    case Waived = 'waived';
}
