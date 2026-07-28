<?php

namespace App\Enums\Sell;

enum SellListingDraftStatus: string
{
    case Ready = 'ready';
    case ReviewRequired = 'review_required';
}
