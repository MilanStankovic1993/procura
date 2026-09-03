<?php

namespace App\Enums\Outcomes;

enum ActualSaleOutcomeType: string
{
    case Sold = 'sold';
    case Cancelled = 'cancelled';
    case NoSale = 'no_sale';

    public function hasRealizedMoney(): bool
    {
        return $this === self::Sold;
    }
}
