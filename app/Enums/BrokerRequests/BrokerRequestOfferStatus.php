<?php

namespace App\Enums\BrokerRequests;

enum BrokerRequestOfferStatus: string
{
    case Presented = 'presented';
    case Accepted = 'accepted';
    case NotSelected = 'not_selected';

    public function isResolved(): bool
    {
        return $this !== self::Presented;
    }
}
