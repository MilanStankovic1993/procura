<?php

namespace App\Enums\OwnedProducts;

enum OwnedProductAssessmentStatus: string
{
    case Ready = 'ready';
    case NeedsInput = 'needs_input';
    case ReviewRequired = 'review_required';
}
