<?php

namespace App\Enums\OwnedProducts;

enum OwnedProductStatus: string
{
    case Draft = 'draft';
    case Ready = 'ready';
    case Archived = 'archived';

    public function canTransitionTo(self $target): bool
    {
        if ($this === $target) {
            return true;
        }

        return match ($this) {
            self::Draft => in_array($target, [self::Ready, self::Archived], true),
            self::Ready => in_array($target, [self::Draft, self::Archived], true),
            self::Archived => false,
        };
    }
}
