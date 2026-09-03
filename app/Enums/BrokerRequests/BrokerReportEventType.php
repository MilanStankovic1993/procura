<?php

namespace App\Enums\BrokerRequests;

enum BrokerReportEventType: string
{
    case Generated = 'generated';
    case Purged = 'purged';
}
