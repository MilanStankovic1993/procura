<?php

namespace App\Filament\Resources\ProductMatchReviews\Pages;

use App\Filament\Resources\ProductMatchReviews\ProductMatchReviewResource;
use Filament\Resources\Pages\ListRecords;

final class ListProductMatchReviews extends ListRecords
{
    protected static string $resource = ProductMatchReviewResource::class;
}
