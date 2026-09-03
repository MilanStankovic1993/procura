<?php

namespace App\Enums\Monitoring;

enum TelegramConnectionEventType: string
{
    case Pending = 'pending';
    case Connected = 'connected';
    case Revoked = 'revoked';
    case Expired = 'expired';
}
