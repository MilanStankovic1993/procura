<?php

namespace App\Console\Commands;

use App\Actions\Markets\SyncMarketReferenceData;
use Illuminate\Console\Command;

class SyncMarketReferenceDataCommand extends Command
{
    protected $signature = 'markets:sync-reference';

    protected $description = 'Synchronize ISO/ICU country and currency reference data';

    public function handle(SyncMarketReferenceData $sync): int
    {
        $counts = $sync->sync();

        $this->components->info(sprintf(
            'Synchronized %d continents, %d countries, and %d currencies from %s.',
            $counts['continents'],
            $counts['countries'],
            $counts['currencies'],
            $counts['source_version'],
        ));

        return self::SUCCESS;
    }
}
