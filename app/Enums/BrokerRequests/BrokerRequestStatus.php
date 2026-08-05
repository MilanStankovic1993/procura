<?php

namespace App\Enums\BrokerRequests;

enum BrokerRequestStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Reviewing = 'reviewing';
    case Searching = 'searching';
    case OffersAvailable = 'offers_available';
    case Accepted = 'accepted';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function subjectCanCancel(): bool
    {
        return in_array($this, [
            self::Draft,
            self::Submitted,
            self::Reviewing,
            self::Searching,
            self::OffersAvailable,
        ], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled], true);
    }
}
