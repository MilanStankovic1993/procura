<?php

namespace App\Filament\Resources\ProductVariants\Pages;

use App\Filament\Resources\ProductVariants\ProductVariantResource;
use Filament\Resources\Pages\ListRecords;

final class ListProductVariants extends ListRecords
{
    protected static string $resource = ProductVariantResource::class;
}
