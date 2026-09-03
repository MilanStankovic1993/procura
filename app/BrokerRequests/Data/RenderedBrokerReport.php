<?php

namespace App\BrokerRequests\Data;

final readonly class RenderedBrokerReport
{
    public function __construct(
        public string $bytes,
        public int $pageCount,
    ) {}
}
