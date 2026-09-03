<?php

namespace App\Jobs;

use App\Actions\MarketplaceImports\ProcessMarketplaceImport as ProcessImport;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessMarketplaceImport implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 900;

    public int $uniqueFor = 3600;

    public function __construct(public readonly string $importId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return array_map(
            'intval',
            config('marketplace_connectors.retry_delays_seconds'),
        );
    }

    public function uniqueId(): string
    {
        return $this->importId;
    }

    public function handle(ProcessImport $processor): void
    {
        $processor->process($this->importId);
    }
}
