<?php

namespace App\Enums\Comparables;

enum ComparableSetStatus: string
{
    case Ready = 'ready';
    case Insufficient = 'insufficient';
}
