<?php

namespace App\Enums\Comparables;

enum ComparableListingType: string
{
    case Product = 'product';
    case SparePart = 'spare_part';
    case BrokenOnly = 'broken_only';
    case Wanted = 'wanted';
    case Rental = 'rental';
    case UnclearBundle = 'unclear_bundle';
}
