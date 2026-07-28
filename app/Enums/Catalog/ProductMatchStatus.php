<?php

namespace App\Enums\Catalog;

enum ProductMatchStatus: string
{
    case Matched = 'matched';
    case ReviewRequired = 'review_required';
    case Unmatched = 'unmatched';
}
