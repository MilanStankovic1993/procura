<?php

namespace App\Actions\CatalogImports;

use App\Catalog\CatalogTextNormalizer;
use App\Enums\Catalog\CatalogImportRowStatus;
use App\Enums\Catalog\CatalogImportStatus;
use App\Exceptions\CatalogImportConflictException;
use App\Models\Brand;
use App\Models\CatalogImport;
use App\Models\CatalogImportRow;
use App\Models\ProductAlias;
use App\Models\ProductCategory;
use App\Models\ProductModel;
use App\Models\ProductVariant;
use App\Models\ProductVariantMarket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ProcessCatalogImport
{
    private const REQUIRED_HEADERS = [
        'category_name',
        'category_slug',
        'brand_name',
        'model_name',
        'model_number',
        'model_canonical_key',
        'variant_name',
        'variant_canonical_key',
    ];

    public function process(string $importId): CatalogImport
    {
        $import = $this->claim($importId);

        if ($import === null) {
            return CatalogImport::query()->findOrFail($importId);
        }

        try {
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

                    if (CatalogImportRow::query()
                        ->where('catalog_import_id', $import->getKey())
                        ->where('row_number', $lineNumber)
                        ->exists()) {
                        continue;
                    }

                    $payload = count($headers) === count($columns)
                        ? array_combine($headers, $columns)
                        : ['_columns' => $columns];

                    if (! is_array($payload)) {
                        throw new RuntimeException('The catalog CSV row could not be combined.');
                    }

                    $this->processRow(
                        $import,
                        $lineNumber,
                        $payload,
                        count($headers) === count($columns),
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

    private function claim(string $importId): ?CatalogImport
    {
        return DB::transaction(function () use ($importId): ?CatalogImport {
            $import = CatalogImport::query()->lockForUpdate()->findOrFail($importId);

            if (in_array($import->status, [
                CatalogImportStatus::Completed,
                CatalogImportStatus::CompletedWithErrors,
            ], true)) {
                return null;
            }

            if ($import->status === CatalogImportStatus::Processing
                && $import->started_at?->gt(now()->subSeconds(
                    (int) config('catalog.processing_timeout_seconds'),
                ))) {
                return null;
            }

            $import->update([
                'status' => CatalogImportStatus::Processing,
                'processing_attempts' => $import->processing_attempts + 1,
                'started_at' => now(),
                'completed_at' => null,
                'failed_at' => null,
                'last_error_code' => null,
                'last_error_message' => null,
            ]);

            return $import->fresh();
        }, attempts: 3);
    }

    /** @return array{0: list<string>, 1: int} */
    private function preflight(CatalogImport $import): array
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

                if ($totalRows > (int) config('catalog.max_rows')) {
                    throw new InvalidArgumentException('catalog_csv_row_limit_exceeded');
                }
            }

            return [$headers, $totalRows];
        } finally {
            fclose($stream);
        }
    }

    /** @param resource $stream @return list<string> */
    private function readHeader($stream, CatalogImport $import): array
    {
        $columns = fgetcsv($stream, 0, $this->delimiter($import->delimiter), '"', '');

        if ($columns === false || $this->isBlankRow($columns)) {
            throw new InvalidArgumentException('catalog_csv_header_missing');
        }

        $headers = array_map(function (mixed $header, int $index): string {
            $value = trim((string) $header);

            if ($index === 0) {
                $value = ltrim($value, "\xEF\xBB\xBF");
            }

            return str($value)->snake()->toString();
        }, $columns, array_keys($columns));

        if (count($headers) !== count(array_unique($headers)) || in_array('', $headers, true)) {
            throw new InvalidArgumentException('catalog_csv_header_invalid');
        }

        $missing = array_diff(self::REQUIRED_HEADERS, $headers);

        if ($missing !== []) {
            throw new InvalidArgumentException(
                'catalog_csv_required_headers_missing:'.implode(',', $missing),
            );
        }

        return array_values($headers);
    }

    /** @param resource $stream @return list<string|null>|false */
    private function readRow($stream, CatalogImport $import): array|false
    {
        return fgetcsv($stream, 0, $this->delimiter($import->delimiter), '"', '');
    }

    /** @param array<int, mixed> $row */
    private function isBlankRow(array $row): bool
    {
        return collect($row)->every(
            static fn (mixed $value): bool => trim((string) $value) === '',
        );
    }

    /** @param array<string, mixed> $rawPayload */
    private function processRow(
        CatalogImport $import,
        int $rowNumber,
        array $rawPayload,
        bool $columnCountMatches,
    ): void {
        $rowHash = hash('sha256', json_encode(
            $rawPayload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        if (! $columnCountMatches) {
            $this->recordRow(
                $import,
                $rowNumber,
                CatalogImportRowStatus::Rejected,
                $rawPayload,
                null,
                ['csv' => ['column_count_mismatch']],
                $rowHash,
            );

            return;
        }

        try {
            $normalized = $this->normalize($rawPayload);
        } catch (InvalidArgumentException $exception) {
            $this->recordRow(
                $import,
                $rowNumber,
                CatalogImportRowStatus::Rejected,
                $rawPayload,
                null,
                ['payload' => [$exception->getMessage()]],
                $rowHash,
            );

            return;
        }

        $validator = Validator::make($normalized, [
            'category_name' => ['required', 'string', 'max:120'],
            'category_slug' => ['required', 'string', 'max:140', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'brand_name' => ['required', 'string', 'max:120'],
            'model_name' => ['required', 'string', 'max:160'],
            'model_number' => ['required', 'string', 'max:120'],
            'model_canonical_key' => ['required', 'string', 'max:220', 'regex:/^[a-z0-9]+(?:[._:-][a-z0-9]+)*$/'],
            'variant_name' => ['required', 'string', 'max:180'],
            'variant_canonical_key' => ['required', 'string', 'max:240', 'regex:/^[a-z0-9]+(?:[._:-][a-z0-9]+)*$/'],
            'sku' => ['nullable', 'string', 'max:120'],
            'country_code' => ['nullable', 'string', 'size:2', 'exists:countries,code'],
            'market_model_number' => ['nullable', 'string', 'max:120'],
            'voltage_millivolts' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'plug_type' => ['nullable', 'string', 'max:32'],
            'measurement_system' => ['nullable', 'in:metric,us_customary,uk_mixed'],
            'warranty_applicable' => ['nullable', 'boolean'],
            'model_specifications' => ['nullable', 'array'],
            'variant_attributes' => ['nullable', 'array'],
            'included_accessories' => ['nullable', 'array'],
            'aliases' => ['array'],
            'aliases.*' => ['string', 'min:2', 'max:180'],
            'alias_locale' => ['nullable', 'string', 'max:35'],
            'active' => ['boolean'],
        ]);

        if ($validator->fails()) {
            $errors = collect($validator->failed())
                ->map(static fn (array $rules): array => collect(array_keys($rules))
                    ->map(static fn (string $rule): string => str($rule)->snake()->toString())
                    ->values()
                    ->all())
                ->all();

            $this->recordRow(
                $import,
                $rowNumber,
                CatalogImportRowStatus::Rejected,
                $rawPayload,
                $normalized,
                $errors,
                $rowHash,
            );

            return;
        }

        $attributes = $validator->validated();

        try {
            [$created, $category, $brand, $model, $variant] = DB::transaction(
                fn (): array => $this->applyRow($import, $attributes),
                attempts: 3,
            );
        } catch (CatalogImportConflictException $exception) {
            $this->recordRow(
                $import,
                $rowNumber,
                CatalogImportRowStatus::Rejected,
                $rawPayload,
                $attributes,
                ['catalog' => [$exception->getMessage()]],
                $rowHash,
            );

            return;
        }

        $this->recordRow(
            $import,
            $rowNumber,
            $created ? CatalogImportRowStatus::Imported : CatalogImportRowStatus::Unchanged,
            $rawPayload,
            $attributes,
            null,
            $rowHash,
            $category,
            $brand,
            $model,
            $variant,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalize(array $payload): array
    {
        $string = static fn (string $key): ?string => filled($payload[$key] ?? null)
            ? trim((string) $payload[$key])
            : null;

        return [
            'category_name' => $string('category_name'),
            'category_slug' => Str::lower((string) $string('category_slug')),
            'brand_name' => $string('brand_name'),
            'model_name' => $string('model_name'),
            'model_number' => $string('model_number'),
            'model_canonical_key' => Str::lower((string) $string('model_canonical_key')),
            'variant_name' => $string('variant_name'),
            'variant_canonical_key' => Str::lower((string) $string('variant_canonical_key')),
            'sku' => $string('sku'),
            'country_code' => filled($payload['country_code'] ?? null)
                ? Str::upper(trim((string) $payload['country_code']))
                : null,
            'market_model_number' => $string('market_model_number'),
            'voltage_millivolts' => filled($payload['voltage_millivolts'] ?? null)
                ? filter_var($payload['voltage_millivolts'], FILTER_VALIDATE_INT)
                : null,
            'plug_type' => $string('plug_type'),
            'measurement_system' => $string('measurement_system'),
            'warranty_applicable' => $this->nullableBoolean(
                $payload['warranty_applicable'] ?? null,
                'warranty_applicable_invalid',
            ),
            'model_specifications' => $this->jsonObject(
                $payload['model_specifications'] ?? null,
                'model_specifications_json_invalid',
            ),
            'variant_attributes' => $this->jsonObject(
                $payload['variant_attributes'] ?? null,
                'variant_attributes_json_invalid',
            ),
            'included_accessories' => $this->jsonList(
                $payload['included_accessories'] ?? null,
                'included_accessories_json_invalid',
            ),
            'aliases' => collect(explode('|', (string) ($payload['aliases'] ?? '')))
                ->map(static fn (string $alias): string => trim($alias))
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'alias_locale' => $string('alias_locale'),
            'active' => $this->nullableBoolean(
                $payload['active'] ?? null,
                'active_invalid',
            ) ?? true,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{bool, ProductCategory, Brand, ProductModel, ProductVariant}
     */
    private function applyRow(CatalogImport $import, array $attributes): array
    {
        $created = false;
        $category = ProductCategory::query()->where('slug', $attributes['category_slug'])->first();

        if ($category === null) {
            $category = ProductCategory::query()->create([
                'name' => $attributes['category_name'],
                'slug' => $attributes['category_slug'],
                'active' => $attributes['active'],
            ]);
            $created = true;
        } elseif ($this->normalized($category->name) !== $this->normalized($attributes['category_name'])
            || $category->active !== $attributes['active']) {
            throw new CatalogImportConflictException('category_slug_conflicts_with_existing_name');
        }

        $normalizedBrand = CatalogTextNormalizer::normalize($attributes['brand_name']);
        $brand = Brand::query()->where('normalized_name', $normalizedBrand)->first();

        if ($brand === null) {
            $brand = Brand::query()->create([
                'name' => $attributes['brand_name'],
                'active' => $attributes['active'],
            ]);
            $created = true;
        } elseif ($brand->active !== $attributes['active']) {
            throw new CatalogImportConflictException('brand_conflicts_with_existing_data');
        }

        $identityModel = ProductModel::query()
            ->where('brand_id', $brand->getKey())
            ->where('normalized_model_number', CatalogTextNormalizer::normalize($attributes['model_number']))
            ->first();
        $keyModel = ProductModel::query()
            ->where('canonical_key', $attributes['model_canonical_key'])
            ->first();

        if ($identityModel !== null && $keyModel !== null && ! $identityModel->is($keyModel)) {
            throw new CatalogImportConflictException('model_identity_resolves_to_different_records');
        }

        $model = $identityModel ?? $keyModel;

        if ($model === null) {
            $model = ProductModel::query()->create([
                'brand_id' => $brand->getKey(),
                'product_category_id' => $category->getKey(),
                'name' => $attributes['model_name'],
                'model_number' => $attributes['model_number'],
                'canonical_key' => $attributes['model_canonical_key'],
                'specifications' => $attributes['model_specifications'],
                'active' => $attributes['active'],
            ]);
            $created = true;
        } else {
            $this->assertModelUnchanged($model, $category, $brand, $attributes);
        }

        $variant = ProductVariant::query()
            ->where('canonical_key', $attributes['variant_canonical_key'])
            ->first();

        if ($variant === null) {
            $variant = ProductVariant::query()->create([
                'product_model_id' => $model->getKey(),
                'name' => $attributes['variant_name'],
                'canonical_key' => $attributes['variant_canonical_key'],
                'sku' => $attributes['sku'],
                'attributes' => $attributes['variant_attributes'],
                'active' => $attributes['active'],
            ]);
            $created = true;
        } else {
            $this->assertVariantUnchanged($variant, $model, $attributes);
        }

        if ($attributes['country_code'] !== null) {
            $market = ProductVariantMarket::query()
                ->where('product_variant_id', $variant->getKey())
                ->where('country_code', $attributes['country_code'])
                ->first();
            $marketAttributes = [
                'market_model_number' => $attributes['market_model_number'],
                'voltage_millivolts' => $attributes['voltage_millivolts'],
                'plug_type' => $attributes['plug_type'],
                'measurement_system' => $attributes['measurement_system'],
                'warranty_applicable' => $attributes['warranty_applicable'],
                'included_accessories' => $attributes['included_accessories'],
            ];

            if ($market === null) {
                $variant->marketContexts()->create([
                    'country_code' => $attributes['country_code'],
                    ...$marketAttributes,
                ]);
                $created = true;
            } elseif (! $this->sameValues($market, $marketAttributes)) {
                throw new CatalogImportConflictException('variant_market_conflicts_with_existing_data');
            }
        }

        foreach ($attributes['aliases'] as $aliasValue) {
            $normalizedAlias = CatalogTextNormalizer::normalize($aliasValue);
            $alias = ProductAlias::query()
                ->where('normalized_alias', $normalizedAlias)
                ->where('locale', $attributes['alias_locale'])
                ->where('country_code', $attributes['country_code'])
                ->where('active', true)
                ->first();

            if ($alias !== null && (
                $alias->product_model_id !== $model->getKey()
                || $alias->product_variant_id !== $variant->getKey()
            )) {
                throw new CatalogImportConflictException('alias_conflicts_with_existing_product');
            }

            if ($alias === null) {
                ProductAlias::query()->create([
                    'product_model_id' => $model->getKey(),
                    'product_variant_id' => $variant->getKey(),
                    'alias' => $aliasValue,
                    'locale' => $attributes['alias_locale'],
                    'country_code' => $attributes['country_code'],
                    'source' => 'catalog_import',
                    'active' => true,
                ]);
                $created = true;
            }
        }

        return [$created, $category, $brand, $model, $variant];
    }

    /** @param array<string, mixed> $attributes */
    private function assertModelUnchanged(
        ProductModel $model,
        ProductCategory $category,
        Brand $brand,
        array $attributes,
    ): void {
        if ($model->brand_id !== $brand->getKey()
            || $model->product_category_id !== $category->getKey()
            || $model->canonical_key !== $attributes['model_canonical_key']
            || $model->normalized_name !== CatalogTextNormalizer::normalize($attributes['model_name'])
            || $model->normalized_model_number !== CatalogTextNormalizer::normalize($attributes['model_number'])
            || $model->active !== $attributes['active']
            || ! $this->sameJson($model->specifications, $attributes['model_specifications'])) {
            throw new CatalogImportConflictException('model_conflicts_with_existing_data');
        }
    }

    /** @param array<string, mixed> $attributes */
    private function assertVariantUnchanged(
        ProductVariant $variant,
        ProductModel $model,
        array $attributes,
    ): void {
        if ($variant->product_model_id !== $model->getKey()
            || $this->normalized($variant->name) !== $this->normalized($attributes['variant_name'])
            || $variant->normalized_sku !== ($attributes['sku'] === null
                ? null
                : CatalogTextNormalizer::normalize($attributes['sku']))
            || $variant->active !== $attributes['active']
            || ! $this->sameJson($variant->attributes, $attributes['variant_attributes'])) {
            throw new CatalogImportConflictException('variant_conflicts_with_existing_data');
        }
    }

    /** @param array<string, mixed> $attributes */
    private function sameValues(ProductVariantMarket $market, array $attributes): bool
    {
        foreach ($attributes as $key => $value) {
            if ($key === 'included_accessories') {
                if (! $this->sameJson($market->{$key}, $value)) {
                    return false;
                }

                continue;
            }

            if ($market->{$key} !== $value) {
                return false;
            }
        }

        return true;
    }

    private function sameJson(?array $left, ?array $right): bool
    {
        return $this->canonicalJson($left) === $this->canonicalJson($right);
    }

    private function canonicalJson(?array $value): string
    {
        $sort = function (array $items) use (&$sort): array {
            foreach ($items as $key => $item) {
                if (is_array($item)) {
                    $items[$key] = $sort($item);
                }
            }

            if (! array_is_list($items)) {
                ksort($items);
            }

            return $items;
        };

        return json_encode($sort($value ?? []), JSON_THROW_ON_ERROR);
    }

    private function normalized(string $value): string
    {
        return Str::lower(preg_replace('/\s+/', ' ', trim($value)) ?? trim($value));
    }

    private function nullableBoolean(mixed $value, string $error): ?bool
    {
        if (! filled($value)) {
            return null;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($parsed === null) {
            throw new InvalidArgumentException($error);
        }

        return $parsed;
    }

    /** @return array<string, mixed>|null */
    private function jsonObject(mixed $value, string $error): ?array
    {
        $decoded = $this->decodeJson($value, $error);

        if ($decoded !== null && array_is_list($decoded)) {
            throw new InvalidArgumentException($error);
        }

        return $decoded;
    }

    /** @return list<mixed>|null */
    private function jsonList(mixed $value, string $error): ?array
    {
        $decoded = $this->decodeJson($value, $error);

        if ($decoded !== null && ! array_is_list($decoded)) {
            throw new InvalidArgumentException($error);
        }

        return $decoded;
    }

    /** @return array<mixed>|null */
    private function decodeJson(mixed $value, string $error): ?array
    {
        if (! filled($value)) {
            return null;
        }

        try {
            $decoded = json_decode((string) $value, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidArgumentException($error);
        }

        if (! is_array($decoded)) {
            throw new InvalidArgumentException($error);
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $rawPayload
     * @param  array<string, mixed>|null  $normalized
     * @param  array<string, list<string>>|null  $errors
     */
    private function recordRow(
        CatalogImport $import,
        int $rowNumber,
        CatalogImportRowStatus $status,
        array $rawPayload,
        ?array $normalized,
        ?array $errors,
        string $rowHash,
        ?ProductCategory $category = null,
        ?Brand $brand = null,
        ?ProductModel $model = null,
        ?ProductVariant $variant = null,
    ): void {
        CatalogImportRow::query()->create([
            'catalog_import_id' => $import->getKey(),
            'row_number' => $rowNumber,
            'status' => $status,
            'product_category_id' => $category?->getKey(),
            'brand_id' => $brand?->getKey(),
            'product_model_id' => $model?->getKey(),
            'product_variant_id' => $variant?->getKey(),
            'raw_payload' => $rawPayload,
            'normalized_payload' => $normalized,
            'validation_errors' => $errors,
            'row_hash' => $rowHash,
            'processed_at' => now(),
        ]);
    }

    private function complete(CatalogImport $import): CatalogImport
    {
        $counts = $this->rowCounts($import);
        $imported = $counts[CatalogImportRowStatus::Imported->value] ?? 0;
        $unchanged = $counts[CatalogImportRowStatus::Unchanged->value] ?? 0;
        $rejected = $counts[CatalogImportRowStatus::Rejected->value] ?? 0;

        $import->update([
            'status' => $rejected > 0
                ? CatalogImportStatus::CompletedWithErrors
                : CatalogImportStatus::Completed,
            'processed_rows' => $imported + $unchanged + $rejected,
            'imported_rows' => $imported,
            'unchanged_rows' => $unchanged,
            'rejected_rows' => $rejected,
            'completed_at' => now(),
            'failed_at' => null,
            'last_error_code' => null,
            'last_error_message' => null,
        ]);

        return $import->fresh(['createdBy']);
    }

    private function recordFailure(CatalogImport $import, Throwable $exception): void
    {
        $counts = $this->rowCounts($import);
        $imported = $counts[CatalogImportRowStatus::Imported->value] ?? 0;
        $unchanged = $counts[CatalogImportRowStatus::Unchanged->value] ?? 0;
        $rejected = $counts[CatalogImportRowStatus::Rejected->value] ?? 0;

        $import->update([
            'status' => CatalogImportStatus::Failed,
            'processed_rows' => $imported + $unchanged + $rejected,
            'imported_rows' => $imported,
            'unchanged_rows' => $unchanged,
            'rejected_rows' => $rejected,
            'failed_at' => now(),
            'last_error_code' => str(class_basename($exception))->snake()->limit(80, '')->toString(),
            'last_error_message' => str($exception->getMessage())->limit(1000)->toString(),
        ]);
    }

    /** @return array<string, int> */
    private function rowCounts(CatalogImport $import): array
    {
        return CatalogImportRow::query()
            ->where('catalog_import_id', $import->getKey())
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();
    }

    /** @return resource */
    private function stream(CatalogImport $import)
    {
        $stream = Storage::disk($import->disk)->readStream($import->path);

        if ($stream === false) {
            throw new RuntimeException('catalog_import_source_unavailable');
        }

        return $stream;
    }

    private function delimiter(string $delimiter): string
    {
        return match ($delimiter) {
            'comma' => ',',
            'semicolon' => ';',
            'tab' => "\t",
            default => throw new InvalidArgumentException('catalog_csv_delimiter_invalid'),
        };
    }
}
