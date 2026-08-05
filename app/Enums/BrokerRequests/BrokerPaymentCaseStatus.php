<?php

namespace App\Enums\BrokerRequests;

enum BrokerPaymentCaseStatus: string
{
    case Open = 'open';
    case UnderReview = 'under_review';
    case Resolved = 'resolved';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Resolved, self::Cancelled], true);
    }
}
