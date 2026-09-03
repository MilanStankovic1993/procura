<?php

namespace App\Enums\Sell;

enum SellPriceStrategy: string
{
    case QuickSale = 'quick_sale';
    case Recommended = 'recommended';
    case Ambitious = 'ambitious';
}
