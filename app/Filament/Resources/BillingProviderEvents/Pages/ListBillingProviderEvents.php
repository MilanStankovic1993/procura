<?php

namespace App\Filament\Resources\BillingProviderEvents\Pages;

use App\Filament\Resources\BillingProviderEvents\BillingProviderEventResource;
use Filament\Resources\Pages\ListRecords;

final class ListBillingProviderEvents extends ListRecords
{
    protected static string $resource = BillingProviderEventResource::class;
}
