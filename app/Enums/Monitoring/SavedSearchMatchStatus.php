<?php

namespace App\Enums\Monitoring;

enum SavedSearchMatchStatus: string
{
    case Matched = 'matched';
    case NotMatched = 'not_matched';
    case InsufficientEvidence = 'insufficient_evidence';
}
