<?php

namespace App\Filament\Resources\ProductModels\Pages;

use App\Filament\Resources\ProductModels\ProductModelResource;
use Filament\Resources\Pages\ListRecords;

final class ListProductModels extends ListRecords
{
    protected static string $resource = ProductModelResource::class;
}
