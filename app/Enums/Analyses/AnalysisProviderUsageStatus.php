<?php

namespace App\Enums\Analyses;

enum AnalysisProviderUsageStatus: string
{
    case Reserved = 'reserved';
    case Completed = 'completed';
    case Uncertain = 'uncertain';
}
