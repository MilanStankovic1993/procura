<?php

namespace App\Filament\Resources\PriceEstimates\Pages;

use App\Filament\Resources\PriceEstimates\PriceEstimateResource;
use Filament\Resources\Pages\ListRecords;

final class ListPriceEstimates extends ListRecords
{
    protected static string $resource = PriceEstimateResource::class;
}
