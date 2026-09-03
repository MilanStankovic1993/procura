<?php

namespace App\Jobs;

use App\Actions\CatalogImports\ProcessCatalogImport as ProcessImport;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessCatalogImport implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $importId) {}

    public function uniqueId(): string
    {
        return $this->importId;
    }

    public function handle(ProcessImport $imports): void
    {
        $imports->process($this->importId);
    }
}
