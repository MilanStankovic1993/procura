<?php

namespace App\Enums\Analyses;

enum AiAnalysisStatus: string
{
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
