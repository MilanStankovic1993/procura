<?php

namespace App\Actions\MarketplaceImports;

use App\Actions\Listings\ListingAuthorizer;
use App\Enums\Api\ApiErrorCode;
use App\Enums\Listings\MarketplaceImportStatus;
use App\Enums\Organizations\OrganizationPermission;
use App\Exceptions\MarketplaceImportConflictException;
use App\Jobs\ProcessMarketplaceImport;
use App\MarketplaceConnectors\ConnectorRegistry;
use App\Models\MarketplaceImport;
use App\Models\MarketplaceSource;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class CreateMarketplaceImport
{
    public function __construct(
        private readonly ListingAuthorizer $authorizer,
        private readonly ConnectorRegistry $connectors,
    ) {}

    public function create(
        Organization $organization,
        User $actor,
        MarketplaceSource $source,
        UploadedFile $file,
        string $idempotencyKey,
        string $delimiter,
        ?string $defaultTargetCountryCode,
    ): MarketplaceImport {
        $this->authorizer->authorize(
            $organization,
            $actor,
            OrganizationPermission::ManageListings,
        );
        $this->connectors->forSource($source);

        $temporaryPath = $file->getRealPath();
        $contentHash = hash_file('sha256', $temporaryPath);

        if (! is_string($contentHash)) {
            throw new RuntimeException('The import file could not be hashed.');
        }

        $existing = MarketplaceImport::query()
            ->forOrganization($organization)
            ->where(static function ($query) use (
                $idempotencyKey,
                $source,
                $contentHash,
            ): void {
                $query
                    ->where('idempotency_key', $idempotencyKey)
                    ->orWhere(static function ($query) use ($source, $contentHash): void {
                        $query
                            ->where('marketplace_source_id', $source->getKey())
                            ->where('content_hash', $contentHash);
                    });
            })
            ->first();

        if ($existing !== null) {
            if (
                $existing->idempotency_key === $idempotencyKey
                && $existing->content_hash !== $contentHash
            ) {
                throw new MarketplaceImportConflictException(
                    ApiErrorCode::MarketplaceImportIdempotencyPayloadMismatch,
                    'The idempotency key was already used for a different import file.',
                );
            }

            return $existing;
        }

        $importId = (string) Str::ulid();
        $disk = (string) config('marketplace_connectors.import_disk');
        $path = "marketplace-imports/{$organization->getKey()}/{$importId}/source.csv";
        $storedPath = $file->storeAs(
            dirname($path),
            basename($path),
            ['disk' => $disk],
        );

        if ($storedPath === false) {
            throw new RuntimeException('The import file could not be stored.');
        }

        try {
            $import = DB::transaction(function () use (
                $importId,
                $organization,
                $actor,
                $source,
                $file,
                $idempotencyKey,
                $delimiter,
                $defaultTargetCountryCode,
                $disk,
                $path,
                $contentHash,
            ): MarketplaceImport {
                $this->authorizer->authorize(
                    $organization,
                    $actor,
                    OrganizationPermission::ManageListings,
                    lockForUpdate: true,
                );

                return MarketplaceImport::query()->forceCreate([
                    'id' => $importId,
                    'organization_id' => $organization->getKey(),
                    'marketplace_source_id' => $source->getKey(),
                    'created_by_user_id' => $actor->getKey(),
                    'idempotency_key' => $idempotencyKey,
                    'status' => MarketplaceImportStatus::Pending,
                    'schema_version' => 1,
                    'delimiter' => $delimiter,
                    'default_target_country_code' => $defaultTargetCountryCode,
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
                    'authorization_confirmed_at' => now(),
                ]);
            }, attempts: 3);
        } catch (\Throwable $exception) {
            Storage::disk($disk)->delete($path);
            throw $exception;
        }

        DB::afterCommit(
            static fn () => ProcessMarketplaceImport::dispatch($import->getKey())
                ->onQueue((string) config('marketplace_connectors.queue')),
        );

        return $import;
    }
}
