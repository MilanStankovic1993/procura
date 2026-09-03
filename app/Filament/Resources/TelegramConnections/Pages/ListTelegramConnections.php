<?php

namespace App\Filament\Resources\TelegramConnections\Pages;

use App\Filament\Resources\TelegramConnections\TelegramConnectionResource;
use Filament\Resources\Pages\ListRecords;

final class ListTelegramConnections extends ListRecords
{
    protected static string $resource = TelegramConnectionResource::class;
}
