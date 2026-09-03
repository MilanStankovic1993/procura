<?php

namespace App\Enums\Listings;

enum ListingStatus: string
{
    case Active = 'active';
    case Reserved = 'reserved';
    case Sold = 'sold';
    case Removed = 'removed';
    case Expired = 'expired';
    case Unknown = 'unknown';
}
