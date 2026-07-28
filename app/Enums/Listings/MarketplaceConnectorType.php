<?php

namespace App\Enums\Listings;

enum MarketplaceConnectorType: string
{
    case Manual = 'manual';
    case UrlAssisted = 'url_assisted';
    case BrowserExtension = 'browser_extension';
    case Csv = 'csv';
    case Email = 'email';
    case PartnerFeed = 'partner_feed';
    case OfficialApi = 'official_api';
}
