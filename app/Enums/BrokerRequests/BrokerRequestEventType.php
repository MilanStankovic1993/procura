<?php

namespace App\Enums\BrokerRequests;

enum BrokerRequestEventType: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Submitted = 'submitted';
    case ReviewStarted = 'review_started';
    case SearchStarted = 'search_started';
    case OfferPresented = 'offer_presented';
    case OfferAccepted = 'offer_accepted';
    case TransactionCompleted = 'transaction_completed';
    case Cancelled = 'cancelled';
}
