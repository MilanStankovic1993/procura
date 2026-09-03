<?php

namespace App\Enums\Opportunity;

enum ShippingMethod: string
{
    case LocalPickup = 'local_pickup';
    case Parcel = 'parcel';
    case SellerArranged = 'seller_arranged';
    case Freight = 'freight';
}
