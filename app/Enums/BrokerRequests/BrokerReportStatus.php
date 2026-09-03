<?php

namespace App\Enums\BrokerRequests;

enum BrokerReportStatus: string
{
    case Available = 'available';
    case Purged = 'purged';
}
