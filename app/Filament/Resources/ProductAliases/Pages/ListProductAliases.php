<?php

namespace App\Filament\Resources\ProductAliases\Pages;

use App\Filament\Resources\ProductAliases\ProductAliasResource;
use Filament\Resources\Pages\ListRecords;

final class ListProductAliases extends ListRecords
{
    protected static string $resource = ProductAliasResource::class;
}
