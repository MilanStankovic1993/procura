<?php

namespace App\Monitoring\Telegram\Exceptions;

use RuntimeException;

final class TelegramProviderException extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        public readonly ?int $providerStatus = null,
    ) {
        parent::__construct('Telegram provider request failed.');
    }
}
