<?php

namespace App\Enums\Analyses;

enum AnalysisStatus: string
{
    case Draft = 'draft';
    case Queued = 'queued';
    case Processing = 'processing';
    case NeedsInput = 'needs_input';
    case Completed = 'completed';
    case Failed = 'failed';
    case Archived = 'archived';

    public function isTerminal(): bool
    {
        return in_array($this, [self::NeedsInput, self::Completed, self::Archived], true);
    }
}
