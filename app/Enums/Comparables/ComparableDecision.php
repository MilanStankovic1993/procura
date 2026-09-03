<?php

namespace App\Enums\Comparables;

enum ComparableDecision: string
{
    case Included = 'included';
    case Excluded = 'excluded';
}
