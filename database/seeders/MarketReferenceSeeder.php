<?php

namespace Database\Seeders;

use App\Actions\Markets\SyncMarketReferenceData;
use Illuminate\Database\Seeder;

class MarketReferenceSeeder extends Seeder
{
    public function run(SyncMarketReferenceData $sync): void
    {
        $sync->sync();
    }
}
