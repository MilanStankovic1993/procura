<?php

namespace App\Enums\Monitoring;

enum NotificationEventType: string
{
    case Queued = 'queued';
    case Attempting = 'attempting';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Exhausted = 'exhausted';
    case Suppressed = 'suppressed';
    case Read = 'read';
    case Unread = 'unread';
    case Archived = 'archived';

    public function isRead(): bool
    {
        return $this === self::Read;
    }

    public function isArchived(): bool
    {
        return $this === self::Archived;
    }

    public function isTerminalDelivery(): bool
    {
        return in_array(
            $this,
            [self::Delivered, self::Exhausted, self::Suppressed],
            true,
        );
    }
}
