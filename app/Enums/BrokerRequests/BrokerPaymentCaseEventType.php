<?php

namespace App\Enums\BrokerRequests;

enum BrokerPaymentCaseEventType: string
{
    case Opened = 'opened';
    case ReviewStarted = 'review_started';
    case Resolved = 'resolved';
    case Cancelled = 'cancelled';
}
