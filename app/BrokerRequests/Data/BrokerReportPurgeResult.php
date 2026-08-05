<?php

namespace App\BrokerRequests\Data;

final readonly class BrokerReportPurgeResult
{
    public function __construct(
        public int $processed,
        public int $purged,
        public int $failed,
    ) {}
}
