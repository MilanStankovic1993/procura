<?php

namespace App\BrokerRequests\Data;

use App\Models\BrokerReport;
use App\Models\BrokerReportEvent;

final readonly class BrokerReportWriteResult
{
    public function __construct(
        public BrokerReport $report,
        public BrokerReportEvent $event,
        public bool $created,
    ) {}
}
