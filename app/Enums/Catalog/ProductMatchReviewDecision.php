<?php

namespace App\Enums\Catalog;

enum ProductMatchReviewDecision: string
{
    case Confirm = 'confirm';
    case Reject = 'reject';
}
