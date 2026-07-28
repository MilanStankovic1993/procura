<?php

namespace App\Enums\BuyerDecisions;

enum BuyerDecisionState: string
{
    case Interested = 'interested';
    case Contacted = 'contacted';
    case Purchased = 'purchased';
    case Rejected = 'rejected';
    case Archived = 'archived';

    /**
     * @return list<self>
     */
    public static function allowedFrom(?self $current): array
    {
        return match ($current) {
            null => self::cases(),
            self::Interested => [
                self::Contacted,
                self::Purchased,
                self::Rejected,
                self::Archived,
            ],
            self::Contacted => [
                self::Purchased,
                self::Rejected,
                self::Archived,
            ],
            self::Purchased => [self::Archived],
            self::Rejected => [self::Interested, self::Archived],
            self::Archived => [self::Interested],
        };
    }

    public static function canTransition(
        ?self $current,
        self $next,
    ): bool {
        return in_array($next, self::allowedFrom($current), true);
    }
}
