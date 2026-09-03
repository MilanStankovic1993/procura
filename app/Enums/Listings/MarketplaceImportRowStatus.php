<?php

namespace App\Enums\Listings;

enum MarketplaceImportRowStatus: string
{
    case Imported = 'imported';
    case Rejected = 'rejected';
    case Duplicate = 'duplicate';
}
