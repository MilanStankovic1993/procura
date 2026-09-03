<?php

namespace App\Filament\Resources\Listings\Pages;

use App\Filament\Resources\Listings\ListingResource;
use Filament\Resources\Pages\ListRecords;

final class ListListings extends ListRecords
{
    protected static string $resource = ListingResource::class;
}
