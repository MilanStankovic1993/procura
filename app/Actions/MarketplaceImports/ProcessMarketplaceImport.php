<?php

namespace App\Actions\MarketplaceImports;

use App\Actions\Listings\CreateListing;
use App\Enums\Listings\MarketplaceImportRowStatus;
use App\Enums\Listings\MarketplaceImportStatus;
use App\Http\Requests\Api\V1\Listings\StoreListingRequest;
use App\MarketplaceConnectors\ConnectorRegistry;
use App\Models\Listing;
use App\Models\MarketplaceImport;
use App\Models\MarketplaceImportRow;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ProcessMarketplaceImport
{
    private const REQUIRED_HEADERS = [
        'external_id',
        'marketplace_name',
        'title',
        'source_country_code',
    ];

    public function __construct(
        private readonly ConnectorRegistry $connectors,
        private readonly CreateListing $listings,
    ) {}

    public function process(string $importId): MarketplaceImport
    {
        $import = $this->claim($importId);

        if ($import === null) {
            return MarketplaceImport::query()->findOrFail($importId);
        }

        try {
            $source = $import->marketplaceSource()->firstOrFail();
            $actor = User::query()->findOrFail($import->created_by_user_id);
            $connector = $this->connectors->forSource($source);
            [$headers, $totalRows] = $this->preflight($import);

            $import->update(['total_rows' => $totalRows]);
            $stream = $this->stream($import);

            try {
                $this->readHeader($stream, $import);
                $lineNumber = 1;

                while (($columns = $this->readRow($stream, $import)) !== false) {
                    $lineNumber++;

                    if ($this->isBlankRow($columns)) {
                        continue;
                    }

                    if (
                        MarketplaceImportRow::query()
                            ->where('marketplace_import_id', $import->getKey())
                            ->where('row_number', $lineNumber)
                            ->exists()
                    ) {
                        continue;
                    }

                    $rawPayload = count($headers) === count($columns)
                        ? array_combine($headers, $columns)
                        : ['_columns' => $columns];

                    if (! is_array($rawPayload)) {
                        throw new RuntimeException('The CSV row could not be combined.');
                    }

                    $this->processRow(
                        $import,
                        $actor,
                        $lineNumber,
                        $rawPayload,
                        count($headers) === count($columns),
                        $connector->normalize($rawPayload, $import)->attributes,
                    );
                }
            } finally {
                fclose($stream);
            }

            return $this->complete($import);
        } catch (Throwable $exception) {
            $this->recordFailure($import, $exception);
            throw $exception;
        }
    }

    private function claim(string $importId): ?MarketplaceImport
    {
        return DB::transaction(function () use ($importId): ?MarketplaceImport {
            $import = MarketplaceImport::query()
                ->lockForUpdate()
                ->findOrFail($importId);

            if (in_array($import->status, [
                MarketplaceImportStatus::Completed,
                MarketplaceImportStatus::CompletedWithErrors,
            ], true)) {
                return null;
            }

            if (
                $import->status === MarketplaceImportStatus::Processing
                && $import->started_at?->gt(
                    now()->subSeconds(
                        (int) config(
                            'marketplace_connectors.processing_timeout_seconds',
                        ),
                    ),
                )
            ) {
                return null;
            }

            $import->update([
                'status' => MarketplaceImportStatus::Processing,
                'processing_attempts' => $import->processing_attempts + 1,
                'started_at' => now(),
                'completed_at' => null,
                'failed_at' => null,
                'last_error_code' => null,
                'last_error_message' => null,
            ]);

            return $import->fresh(['marketplaceSource']);
        }, attempts: 3);
    }

    /**
     * @return array{0: list<string>, 1: int}
     */
    private function preflight(MarketplaceImport $import): array
    {
        $stream = $this->stream($import);

        try {
            $headers = $this->readHeader($stream, $import);
            $totalRows = 0;

            while (($row = $this->readRow($stream, $import)) !== false) {
                if ($this->isBlankRow($row)) {
                    continue;
                }

                $totalRows++;

                if ($totalRows > (int) config('marketplace_connectors.max_rows')) {
                    throw new InvalidArgumentException('csv_row_limit_exceeded');
                }
            }

            return [$headers, $totalRows];
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  resource  $stream
     * @return list<string>
     */
    private function readHeader($stream, MarketplaceImport $import): array
    {
        $columns = fgetcsv(
            $stream,
            0,
            $this->delimiter($import->delimiter),
            '"',
            '',
        );

        if ($columns === false || $this->isBlankRow($columns)) {
            throw new InvalidArgumentException('csv_header_missing');
        }

        $headers = array_map(function (mixed $header, int $index): string {
            $value = trim((string) $header);

            if ($index === 0) {
                $value = ltrim($value, "\xEF\xBB\xBF");
            }

            return str($value)->snake()->toString();
        }, $columns, array_keys($columns));

        if (
            count($headers) !== count(array_unique($headers))
            || in_array('', $headers, true)
        ) {
            throw new InvalidArgumentException('csv_header_invalid');
        }

        $missing = array_diff(self::REQUIRED_HEADERS, $headers);

        if ($missing !== []) {
            throw new InvalidArgumentException(
                'csv_required_headers_missing:'.implode(',', $missing),
            );
        }

        return array_values($headers);
    }

    /**
     * @param  resource  $stream
     * @return list<string|null>|false
     */
    private function readRow($stream, MarketplaceImport $import): array|false
    {
        return fgetcsv(
            $stream,
            0,
            $this->delimiter($import->delimiter),
            '"',
            '',
        );
    }

    /**
     * @param  array<int, mixed>  $row
     */
    private function isBlankRow(array $row): bool
    {
        return collect($row)->every(
            static fn (mixed $value): bool => trim((string) $value) === '',
        );
    }

    /**
     * @param  array<string, mixed>  $rawPayload
     * @param  array<string, mixed>  $normalized
     */
    private function processRow(
        MarketplaceImport $import,
        User $actor,
        int $rowNumber,
        array $rawPayload,
        bool $columnCountMatches,
        array $normalized,
    ): void {
        DB::transaction(function () use (
            $import,
            $actor,
            $rowNumber,
            $rawPayload,
            $columnCountMatches,
            $normalized,
        ): void {
            $rowHash = hash(
                'sha256',
                json_encode(
                    $rawPayload,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                ),
            );

            if (! $columnCountMatches) {
                $this->recordRow(
                    $import,
                    $rowNumber,
                    MarketplaceImportRowStatus::Rejected,
                    $rawPayload,
                    null,
                    ['csv' => ['column_count_mismatch']],
                    $rowHash,
                );

                return;
            }

            $rules = StoreListingRequest::listingRules(required: true);
            $rules['external_id'] = ['required', 'string', 'max:128'];
            $validator = Validator::make($normalized, $rules);

            if ($validator->fails()) {
                $failed = collect($validator->failed())
                    ->map(
                        static fn (array $rules): array => collect(array_keys($rules))
                            ->map(static fn (string $rule): string => str($rule)->snake()->toString())
                            ->values()
                            ->all(),
                    )
                    ->all();

                $this->recordRow(
                    $import,
                    $rowNumber,
                    MarketplaceImportRowStatus::Rejected,
                    $rawPayload,
                    $normalized,
                    $failed,
                    $rowHash,
                );

                return;
            }

            $attributes = $validator->validated();
            $marketplaceKey = CreateListing::marketplaceKey(
                $attributes['marketplace_name'],
            );
            $existingListing = Listing::query()
                ->forOrganization($import->organization)
                ->where('marketplace_source_id', $import->marketplace_source_id)
                ->where('marketplace_key', $marketplaceKey)
                ->where('external_id', $attributes['external_id'])
                ->first();

            if ($existingListing !== null) {
                $this->recordRow(
                    $import,
                    $rowNumber,
                    MarketplaceImportRowStatus::Duplicate,
                    $rawPayload,
                    $attributes,
                    null,
                    $rowHash,
                    $existingListing,
                );

                return;
            }

            $listing = $this->listings->create(
                $import->organization,
                $actor,
                $import->marketplaceSource,
                $attributes,
                [
                    'event' => 'connector_import',
                    'connector_key' => $import->marketplaceSource->key,
                    'marketplace_import_id' => $import->getKey(),
                    'row_number' => $rowNumber,
                    'row_hash' => $rowHash,
                    'raw_payload' => $rawPayload,
                ],
            );

            $this->recordRow(
                $import,
                $rowNumber,
                MarketplaceImportRowStatus::Imported,
                $rawPayload,
                $attributes,
                null,
                $rowHash,
                $listing,
            );
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $rawPayload
     * @param  array<string, mixed>|null  $normalized
     * @param  array<string, list<string>>|null  $errors
     */
    private function recordRow(
        MarketplaceImport $import,
        int $rowNumber,
        MarketplaceImportRowStatus $status,
        array $rawPayload,
        ?array $normalized,
        ?array $errors,
        string $rowHash,
        ?Listing $listing = null,
    ): void {
        MarketplaceImportRow::query()->create([
            'marketplace_import_id' => $import->getKey(),
            'row_number' => $rowNumber,
            'status' => $status,
            'listing_id' => $listing?->getKey(),
            'raw_payload' => $rawPayload,
            'normalized_payload' => $normalized,
            'validation_errors' => $errors,
            'row_hash' => $rowHash,
            'processed_at' => now(),
        ]);
    }

    private function complete(MarketplaceImport $import): MarketplaceImport
    {
        $counts = MarketplaceImportRow::query()
            ->where('marketplace_import_id', $import->getKey())
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');
        $imported = (int) ($counts[MarketplaceImportRowStatus::Imported->value] ?? 0);
        $rejected = (int) ($counts[MarketplaceImportRowStatus::Rejected->value] ?? 0);
        $duplicate = (int) ($counts[MarketplaceImportRowStatus::Duplicate->value] ?? 0);
        $processed = $imported + $rejected + $duplicate;

        $import->update([
            'status' => $rejected > 0
                ? MarketplaceImportStatus::CompletedWithErrors
                : MarketplaceImportStatus::Completed,
            'processed_rows' => $processed,
            'imported_rows' => $imported,
            'rejected_rows' => $rejected,
            'duplicate_rows' => $duplicate,
            'completed_at' => now(),
            'failed_at' => null,
            'last_error_code' => null,
            'last_error_message' => null,
        ]);

        return $import->fresh(['marketplaceSource', 'createdBy']);
    }

    private function recordFailure(
        MarketplaceImport $import,
        Throwable $exception,
    ): void {
        $counts = MarketplaceImportRow::query()
            ->where('marketplace_import_id', $import->getKey())
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');
        $imported = (int) ($counts[MarketplaceImportRowStatus::Imported->value] ?? 0);
        $rejected = (int) ($counts[MarketplaceImportRowStatus::Rejected->value] ?? 0);
        $duplicate = (int) ($counts[MarketplaceImportRowStatus::Duplicate->value] ?? 0);

        $import->update([
            'status' => MarketplaceImportStatus::Failed,
            'processed_rows' => $imported + $rejected + $duplicate,
            'imported_rows' => $imported,
            'rejected_rows' => $rejected,
            'duplicate_rows' => $duplicate,
            'failed_at' => now(),
            'last_error_code' => str(class_basename($exception))
                ->snake()
                ->limit(80, '')
                ->toString(),
            'last_error_message' => str($exception->getMessage())
                ->limit(1000)
                ->toString(),
        ]);
    }

    /** @return resource */
    private function stream(MarketplaceImport $import)
    {
        $stream = Storage::disk($import->disk)->readStream($import->path);

        if ($stream === false) {
            throw new RuntimeException('marketplace_import_source_unavailable');
        }

        return $stream;
    }

    private function delimiter(string $delimiter): string
    {
        return match ($delimiter) {
            'comma' => ',',
            'semicolon' => ';',
            'tab' => "\t",
            default => throw new InvalidArgumentException('csv_delimiter_invalid'),
        };
    }
}
