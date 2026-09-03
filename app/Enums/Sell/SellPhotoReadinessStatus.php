<?php

namespace App\Enums\Sell;

enum SellPhotoReadinessStatus: string
{
    case Ready = 'ready';
    case NeedsPhotos = 'needs_photos';
    case ReviewRequired = 'review_required';
}
