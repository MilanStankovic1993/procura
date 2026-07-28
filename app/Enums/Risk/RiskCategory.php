<?php

namespace App\Enums\Risk;

enum RiskCategory: string
{
    case Listing = 'listing';
    case Seller = 'seller';
    case Product = 'product';
    case Transaction = 'transaction';
}
