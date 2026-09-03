<?php

namespace App\Enums\DealScoring;

enum DealScoreImpact: string
{
    case Strengthens = 'strengthens';
    case Neutral = 'neutral';
    case Reduces = 'reduces';
    case Unknown = 'unknown';
}
