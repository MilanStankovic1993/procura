<?php

namespace App\Enums\BrokerRequests;

enum BrokerRequestOfferEventType: string
{
    case Presented = 'presented';
    case Accepted = 'accepted';
    case NotSelected = 'not_selected';
}
