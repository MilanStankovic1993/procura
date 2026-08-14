<?php

namespace App\Filament\Resources\SavedSearches\Pages;

use App\Filament\Resources\SavedSearches\SavedSearchResource;
use Filament\Resources\Pages\ListRecords;

final class ListSavedSearches extends ListRecords
{
    protected static string $resource = SavedSearchResource::class;
}
