<?php

namespace App\Filament\Resources\OrganizationSettings\Pages;

use App\Filament\Resources\OrganizationSettings\OrganizationSettingResource;
use Filament\Resources\Pages\ListRecords;

final class ListOrganizationSettings extends ListRecords
{
    protected static string $resource = OrganizationSettingResource::class;
}
