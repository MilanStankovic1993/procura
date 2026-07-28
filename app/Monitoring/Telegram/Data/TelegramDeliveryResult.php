<?php

namespace App\Monitoring\Telegram\Data;

final readonly class TelegramDeliveryResult
{
    public function __construct(
        public string $providerMessageId,
    ) {}
}
