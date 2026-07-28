<?php

namespace App\Monitoring\Telegram\Contracts;

use App\Models\TelegramConnection;
use App\Monitoring\Telegram\Data\TelegramDeliveryResult;

interface TelegramProvider
{
    public function sendMessage(
        TelegramConnection $connection,
        string $message,
        string $actionLabel,
        string $actionUrl,
    ): TelegramDeliveryResult;
}
