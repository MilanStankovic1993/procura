<?php

namespace App\Enums\Analyses;

enum AnalysisDispatchStatus: string
{
    case Pending = 'pending';
    case Dispatching = 'dispatching';
    case Dispatched = 'dispatched';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
