<?php

namespace App\Enums\BrokerRequests;

enum BrokerProductCondition: string
{
    case Any = 'any';
    case New = 'new';
    case Used = 'used';
    case Refurbished = 'refurbished';
}
