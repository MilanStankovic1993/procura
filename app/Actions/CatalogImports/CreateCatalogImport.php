<?php

namespace App\Actions\CatalogImports;

use App\Enums\Catalog\CatalogImportStatus;
use App\Exceptions\CatalogImportConflictException;
use App\Jobs\ProcessCatalogImport;
use App\Models\CatalogImport;
use App\Models\PlatformAuditEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;

final class CreateCatalogImport
{
    public function create(
        User $actor,
        UploadedFile $file,
        string $sourceName,
        ?string $sourceUrl,
        string $licenseName,
        string $datasetVersion,
        string $delimiter,
        bool $rightsConfirmed,
        ?string $notes = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): CatalogImport {
        if (! $actor->is_super_admin || ! $actor->hasVerifiedEmail()) {
            throw new AuthorizationException(
                'Only a verified super administrator may import the global catalog.',
            );
        }

        $attributes = Validator::make([
            'source_name' => trim($sourceName),
            'source_url' => filled($sourceUrl) ? trim((string) $sourceUrl) : null,
            'license_name' => trim($licenseName),
            'dataset_version' => trim($datasetVersion),
            'delimiter' => $delimiter,
            'rights_confirmed' => $rightsConfirmed,
            'notes' => filled($notes) ? trim((string) $notes) : null,
            'file' => $file,
        ], [
            'source_name' => ['required', 'string', 'min:2', 'max:160'],
            'source_url' => ['nullable', 'url:http,https', 'max:500'],
            'license_name' => ['required', 'string', 'min:2', 'max:160'],
            'dataset_version' => ['required', 'string', 'min:1', 'max:100'],
            'delimiter' => ['required', 'in:comma,semicolon,tab'],
            'rights_confirmed' => ['accepted'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'file' => [
                'required',
                'file',
                'mimes:csv,txt',
                'max:'.(int) config('catalog.max_file_size_kb'),
            ],
        ])->validate();

        $temporaryPath = $file->getRealPath();

        if (! is_string($temporaryPath) || $temporaryPath === '') {
            throw new RuntimeException('The catalog import file is unavailable.');
        }

        $contentHash = hash_file('sha256', $temporaryPath);

        if (! is_string($contentHash)) {
            throw new RuntimeException('The catalog import file could not be hashed.');
        }

        $sourceKey = Str::slug($attributes['source_name']);

        if ($sourceKey === '') {
            $sourceKey = 'source-'.substr(hash('sha256', $attributes['source_name']), 0, 24);
        }

        $existing = CatalogImport::query()
            ->where('source_key', $sourceKey)
            ->where('dataset_version', $attributes['dataset_version'])
            ->first();

        if ($existing !== null) {
            if ($existing->content_hash !== $contentHash) {
                throw new CatalogImportConflictException(
                    'This source and dataset version already identify a different file.',
                );
            }

            return $existing;
        }

        $importId = (string) Str::ulid();
        $disk = (string) config('catalog.import_disk');
        $path = "catalog-imports/{$importId}/source.csv";
        $storedPath = $file->storeAs(
            dirname($path),
            basename($path),
            ['disk' => $disk],
        );

        if ($storedPath === false) {
            throw new RuntimeException('The catalog import file could not be stored.');
        }

        try {
            $import = DB::transaction(function () use (
                $actor,
                $attributes,
                $file,
                $importId,
                $sourceKey,
                $disk,
                $path,
                $contentHash,
                $ipAddress,
                $userAgent,
            ): CatalogImport {
                $import = CatalogImport::query()->forceCreate([
                    'id' => $importId,
                    'created_by_user_id' => $actor->getKey(),
                    'source_name' => $attributes['source_name'],
                    'source_key' => $sourceKey,
                    'source_url' => $attributes['source_url'],
                    'license_name' => $attributes['license_name'],
                    'dataset_version' => $attributes['dataset_version'],
                    'notes' => $attributes['notes'],
                    'rights_confirmed_at' => now(),
                    'status' => CatalogImportStatus::Pending,
                    'schema_version' => 1,
                    'delimiter' => $attributes['delimiter'],
                    'disk' => $disk,
                    'path' => $path,
                    'original_file_name' => str($file->getClientOriginalName())
                        ->basename()
                        ->limit(180, '')
                        ->toString(),
                    'mime_type' => str($file->getMimeType() ?: 'text/csv')
                        ->limit(100, '')
                        ->toString(),
                    'size_bytes' => $file->getSize(),
                    'content_hash' => $contentHash,
                ]);

                PlatformAuditEvent::query()->create([
                    'actor_user_id' => $actor->getKey(),
                    'action' => 'catalog.import_created',
                    'subject_type' => $import->getMorphClass(),
                    'subject_id' => $import->getKey(),
                    'old_values' => null,
                    'new_values' => [
                        'source_name' => $import->source_name,
                        'dataset_version' => $import->dataset_version,
                        'license_name' => $import->license_name,
                        'content_hash' => $import->content_hash,
                    ],
                    'reason' => 'Catalog source rights confirmed for controlled import.',
                    'ip_address' => $ipAddress,
                    'user_agent' => $userAgent,
                ]);

                return $import;
            }, attempts: 3);
        } catch (\Throwable $exception) {
            Storage::disk($disk)->delete($path);
            throw $exception;
        }

        DB::afterCommit(
            static fn () => ProcessCatalogImport::dispatch($import->getKey())
                ->onQueue((string) config('catalog.import_queue')),
        );

        return $import;
    }
}
