<?php

namespace App\Enums\Sell;

enum SalePortfolioStatus: string
{
    case Draft = 'draft';
    case Listed = 'listed';
    case Reserved = 'reserved';
    case Withdrawn = 'withdrawn';
    case Expired = 'expired';
}
