<?php

namespace App\Enums\Comparables;

enum ComparableSellerType: string
{
    case Private = 'private';
    case Business = 'business';
    case Unknown = 'unknown';
}
