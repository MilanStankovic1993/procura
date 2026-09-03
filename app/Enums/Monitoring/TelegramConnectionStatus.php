<?php

namespace App\Enums\Monitoring;

enum TelegramConnectionStatus: string
{
    case Pending = 'pending';
    case Connected = 'connected';
    case Revoked = 'revoked';
    case Expired = 'expired';

    public function isActive(): bool
    {
        return in_array($this, [self::Pending, self::Connected], true);
    }
}
