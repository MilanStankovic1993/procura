<?php

namespace App\Enums\Catalog;

enum ProductMatchReviewStatus: string
{
    case NotRequired = 'not_required';
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
}
