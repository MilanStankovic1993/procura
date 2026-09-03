<?php

namespace App\Filament\Resources\Alerts\Pages;

use App\Filament\Resources\Alerts\AlertResource;
use Filament\Resources\Pages\ListRecords;

final class ListAlerts extends ListRecords
{
    protected static string $resource = AlertResource::class;
}
