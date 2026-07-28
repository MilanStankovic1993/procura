<?php

namespace App\Enums\Sell;

enum SellPhotoCheckStatus: string
{
    case Satisfied = 'satisfied';
    case Missing = 'missing';
    case ReviewRequired = 'review_required';
    case NotApplicable = 'not_applicable';
}
