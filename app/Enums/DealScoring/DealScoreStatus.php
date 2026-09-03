<?php

namespace App\Enums\DealScoring;

enum DealScoreStatus: string
{
    case Assessed = 'assessed';
    case NeedsInput = 'needs_input';
}
