<?php

namespace App\Enums\Monitoring;

enum NotificationChannel: string
{
    case InApp = 'in_app';
    case Email = 'email';
    case Telegram = 'telegram';
}
