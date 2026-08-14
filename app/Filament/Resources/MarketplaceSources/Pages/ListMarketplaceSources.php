<?php

namespace App\Filament\Resources\MarketplaceSources\Pages;

use App\Filament\Resources\MarketplaceSources\MarketplaceSourceResource;
use Filament\Resources\Pages\ListRecords;

final class ListMarketplaceSources extends ListRecords
{
    protected static string $resource = MarketplaceSourceResource::class;
}
