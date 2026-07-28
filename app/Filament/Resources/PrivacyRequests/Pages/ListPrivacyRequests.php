<?php

namespace App\Filament\Resources\PrivacyRequests\Pages;

use App\Filament\Resources\PrivacyRequests\PrivacyRequestResource;
use Filament\Resources\Pages\ListRecords;

final class ListPrivacyRequests extends ListRecords
{
    protected static string $resource = PrivacyRequestResource::class;
}
