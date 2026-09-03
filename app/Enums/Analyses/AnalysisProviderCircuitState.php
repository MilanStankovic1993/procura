<?php

namespace App\Enums\Analyses;

enum AnalysisProviderCircuitState: string
{
    case Closed = 'closed';
    case Open = 'open';
    case HalfOpen = 'half_open';
}
