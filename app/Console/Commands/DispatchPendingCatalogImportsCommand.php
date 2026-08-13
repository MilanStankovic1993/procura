<?php

namespace App\Console\Commands;

use App\Enums\Catalog\CatalogImportStatus;
use App\Jobs\ProcessCatalogImport;
use App\Models\CatalogImport;
use Illuminate\Console\Command;

final class DispatchPendingCatalogImportsCommand extends Command
{
    protected $signature = 'catalog-imports:dispatch-pending {--limit=100}';

    protected $description = 'Recover pending or stale catalog imports without duplicating queued work.';

    public function handle(): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $staleBefore = now()->subSeconds(
            (int) config('catalog.processing_timeout_seconds'),
        );
        $imports = CatalogImport::query()
            ->where(static function ($query) use ($staleBefore): void {
                $query
                    ->where('status', CatalogImportStatus::Pending)
                    ->orWhere(static function ($query) use ($staleBefore): void {
                        $query
                            ->where('status', CatalogImportStatus::Processing)
                            ->where('started_at', '<=', $staleBefore);
                    });
            })
            ->orderBy('created_at')
            ->limit($limit)
            ->get(['id']);

        foreach ($imports as $import) {
            ProcessCatalogImport::dispatch($import->getKey())
                ->onQueue((string) config('catalog.import_queue'));
        }

        $this->info("Dispatched {$imports->count()} pending catalog imports.");

        return self::SUCCESS;
    }
}
