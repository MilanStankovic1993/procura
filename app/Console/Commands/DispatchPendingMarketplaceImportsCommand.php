<?php

namespace App\Console\Commands;

use App\Enums\Listings\MarketplaceImportStatus;
use App\Jobs\ProcessMarketplaceImport;
use App\Models\MarketplaceImport;
use Illuminate\Console\Command;

final class DispatchPendingMarketplaceImportsCommand extends Command
{
    protected $signature = 'marketplace-imports:dispatch-pending {--limit=100}';

    protected $description = 'Recover pending or stale marketplace imports without duplicating queued work.';

    public function handle(): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $staleBefore = now()->subSeconds(
            (int) config('marketplace_connectors.processing_timeout_seconds'),
        );
        $imports = MarketplaceImport::query()
            ->where(static function ($query) use ($staleBefore): void {
                $query
                    ->where('status', MarketplaceImportStatus::Pending)
                    ->orWhere(static function ($query) use ($staleBefore): void {
                        $query
                            ->where('status', MarketplaceImportStatus::Processing)
                            ->where('started_at', '<=', $staleBefore);
                    });
            })
            ->orderBy('created_at')
            ->limit($limit)
            ->get(['id']);

        foreach ($imports as $import) {
            ProcessMarketplaceImport::dispatch($import->getKey())
                ->onQueue((string) config('marketplace_connectors.queue'));
        }

        $this->info("Dispatched {$imports->count()} pending marketplace imports.");

        return self::SUCCESS;
    }
}
