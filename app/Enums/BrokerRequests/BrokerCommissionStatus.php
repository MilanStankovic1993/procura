<?php

namespace App\Enums\BrokerRequests;

enum BrokerCommissionStatus: string
{
    case Pending = 'pending';
    case Earned = 'earned';
    case Settled = 'settled';
    case Waived = 'waived';
}
