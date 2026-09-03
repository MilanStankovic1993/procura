<?php

namespace App\Enums\OwnedProducts;

enum OwnedProductCondition: string
{
    case New = 'new';
    case LikeNew = 'like_new';
    case UsedGood = 'used_good';
    case UsedFair = 'used_fair';
    case UsedPoor = 'used_poor';
    case Broken = 'broken';
    case Unknown = 'unknown';
}
