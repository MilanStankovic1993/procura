<?php

namespace App\Enums\Opportunity;

enum OpportunityAssessmentStatus: string
{
    case Assessed = 'assessed';
    case LowConfidence = 'low_confidence';
    case NeedsInput = 'needs_input';
}
