<?php

use App\Actions\CatalogImports\CreateCatalogImport;
use App\Actions\CatalogImports\ProcessCatalogImport;
use App\Actions\Markets\SyncMarketReferenceData;
use App\Enums\Catalog\CatalogImportRowStatus;
use App\Enums\Catalog\CatalogImportStatus;
use App\Exceptions\CatalogImportConflictException;
use App\Filament\Resources\CatalogImportRows\CatalogImportRowResource;
use App\Filament\Resources\CatalogImports\CatalogImportResource;
use App\Jobs\ProcessCatalogImport as ProcessCatalogImportJob;
use App\Models\Brand;
use App\Models\CatalogImport;
use App\Models\CatalogImportRow;
use App\Models\PlatformAuditEvent;
use App\Models\ProductAlias;
use App\Models\ProductCategory;
use App\Models\ProductModel;
use App\Models\ProductVariant;
use App\Models\ProductVariantMarket;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    app(SyncMarketReferenceData::class)->sync();
    Storage::fake('local');
    Queue::fake();
});

function catalogImportAdmin(array $attributes = []): User
{
    $admin = User::factory()->create($attributes);
    $admin->forceFill(['is_super_admin' => true])->save();

    return $admin->fresh();
}

function catalogImportCsv(string $variantName = 'EU kit'): string
{
    $headers = [
        'category_name',
        'category_slug',
        'brand_name',
        'model_name',
        'model_number',
        'model_canonical_key',
        'variant_name',
        'variant_canonical_key',
        'sku',
        'country_code',
        'market_model_number',
        'voltage_millivolts',
        'plug_type',
        'measurement_system',
        'warranty_applicable',
        'model_specifications',
        'variant_attributes',
        'included_accessories',
        'aliases',
        'alias_locale',
        'active',
    ];
    $row = [
        'Professional drills',
        'professional-drills',
        'Bosch Professional',
        'GSR 18V-55',
        'GSR 18V-55',
        'bosch-professional:gsr-18v-55',
        $variantName,
        'bosch-professional:gsr-18v-55:eu-kit',
        '06019H5202',
        'DE',
        'GSR 18V-55',
        '18000',
        'F',
        'metric',
        'true',
        '{"max_torque_nm":55}',
        '{"battery_platform":"18V"}',
        '["case","charger"]',
        'GSR 18 V-55|Bosch GSR 18V-55',
        'de',
        'true',
    ];

    $stream = fopen('php://temp', 'r+');

    if ($stream === false) {
        throw new RuntimeException('Unable to create catalog CSV fixture.');
    }

    fputcsv($stream, $headers, ',', '"', '');
    fputcsv($stream, $row, ',', '"', '');
    rewind($stream);
    $csv = stream_get_contents($stream);
    fclose($stream);

    return (string) $csv;
}

function createCatalogImportFixture(
    User $admin,
    string $version,
    ?string $csv = null,
): CatalogImport {
    return app(CreateCatalogImport::class)->create(
        actor: $admin,
        file: UploadedFile::fake()->createWithContent(
            'catalog.csv',
            $csv ?? catalogImportCsv(),
        ),
        sourceName: 'Approved manufacturer export',
        sourceUrl: 'https://manufacturer.example/catalog',
        licenseName: 'Internal distribution agreement 2026',
        datasetVersion: $version,
        delimiter: 'comma',
        rightsConfirmed: true,
        notes: 'Reviewed by catalog operations.',
        ipAddress: '192.0.2.30',
        userAgent: 'Procura catalog import test',
    );
}

test('only a verified super administrator can create an audited private catalog import', function () {
    $ordinaryUser = User::factory()->create();

    expect(fn () => createCatalogImportFixture($ordinaryUser, '2026-08'))
        ->toThrow(AuthorizationException::class);

    $admin = catalogImportAdmin();
    $import = createCatalogImportFixture($admin, '2026-08');

    expect($import->status)->toBe(CatalogImportStatus::Pending)
        ->and($import->source_key)->toBe('approved-manufacturer-export')
        ->and($import->rights_confirmed_at)->not->toBeNull()
        ->and(CatalogImport::query()->count())->toBe(1)
        ->and(PlatformAuditEvent::query()->where('action', 'catalog.import_created')->count())
        ->toBe(1);

    Storage::disk('local')->assertExists($import->path);
    Queue::assertPushed(
        ProcessCatalogImportJob::class,
        fn (ProcessCatalogImportJob $job): bool => $job->importId === $import->getKey(),
    );
});

test('a catalog CSV creates canonical entities and preserves row provenance', function () {
    $import = createCatalogImportFixture(catalogImportAdmin(), '2026-08');

    $processed = app(ProcessCatalogImport::class)->process($import->getKey());
    $row = CatalogImportRow::query()->sole();

    expect($processed->status)->toBe(CatalogImportStatus::Completed)
        ->and($processed->total_rows)->toBe(1)
        ->and($processed->imported_rows)->toBe(1)
        ->and($processed->rejected_rows)->toBe(0)
        ->and($row->status)->toBe(CatalogImportRowStatus::Imported)
        ->and($row->product_model_id)->not->toBeNull()
        ->and(ProductCategory::query()->sole()->slug)->toBe('professional-drills')
        ->and(Brand::query()->sole()->normalized_name)->toBe('bosch professional')
        ->and(ProductModel::query()->sole()->canonical_key)
        ->toBe('bosch-professional:gsr-18v-55')
        ->and(ProductVariant::query()->sole()->canonical_key)
        ->toBe('bosch-professional:gsr-18v-55:eu-kit')
        ->and(ProductVariantMarket::query()->sole()->country_code)->toBe('DE')
        ->and(ProductAlias::query()->count())->toBe(2);
});

test('the same catalog data is idempotent and conflicting identities are rejected', function () {
    $admin = catalogImportAdmin();
    $first = createCatalogImportFixture($admin, '2026-08');
    app(ProcessCatalogImport::class)->process($first->getKey());

    $unchanged = createCatalogImportFixture($admin, '2026-08-rereview');
    $unchanged = app(ProcessCatalogImport::class)->process($unchanged->getKey());

    expect($unchanged->status)->toBe(CatalogImportStatus::Completed)
        ->and($unchanged->unchanged_rows)->toBe(1)
        ->and(ProductModel::query()->count())->toBe(1)
        ->and(ProductVariant::query()->count())->toBe(1);

    $conflict = createCatalogImportFixture(
        $admin,
        '2026-09',
        catalogImportCsv('Conflicting renamed kit'),
    );
    $conflict = app(ProcessCatalogImport::class)->process($conflict->getKey());
    $rejectedRow = $conflict->rows()->sole();

    expect($conflict->status)->toBe(CatalogImportStatus::CompletedWithErrors)
        ->and($conflict->rejected_rows)->toBe(1)
        ->and($rejectedRow->status)->toBe(CatalogImportRowStatus::Rejected)
        ->and($rejectedRow->validation_errors['catalog'])
        ->toBe(['variant_conflicts_with_existing_data'])
        ->and(ProductVariant::query()->sole()->name)->toBe('EU kit');
});

test('a source version cannot be silently replaced by different content', function () {
    $admin = catalogImportAdmin();
    createCatalogImportFixture($admin, '2026-08');

    expect(fn () => createCatalogImportFixture(
        $admin,
        '2026-08',
        catalogImportCsv('Changed content'),
    ))->toThrow(CatalogImportConflictException::class);

    expect(CatalogImport::query()->count())->toBe(1);
});

test('the recovery command dispatches pending and stale catalog imports only', function () {
    $admin = catalogImportAdmin();
    $pending = createCatalogImportFixture($admin, 'pending');
    $stale = createCatalogImportFixture($admin, 'stale');
    $active = createCatalogImportFixture($admin, 'active');
    Queue::fake();

    $stale->update([
        'status' => CatalogImportStatus::Processing,
        'started_at' => now()->subSeconds(
            (int) config('catalog.processing_timeout_seconds') + 1,
        ),
    ]);
    $active->update([
        'status' => CatalogImportStatus::Processing,
        'started_at' => now(),
    ]);

    $this->artisan('catalog-imports:dispatch-pending', ['--limit' => 10])
        ->expectsOutput('Dispatched 2 pending catalog imports.')
        ->assertSuccessful();

    Queue::assertPushed(
        ProcessCatalogImportJob::class,
        fn (ProcessCatalogImportJob $job): bool => $job->importId === $pending->getKey(),
    );
    Queue::assertPushed(
        ProcessCatalogImportJob::class,
        fn (ProcessCatalogImportJob $job): bool => $job->importId === $stale->getKey(),
    );
    Queue::assertNotPushed(
        ProcessCatalogImportJob::class,
        fn (ProcessCatalogImportJob $job): bool => $job->importId === $active->getKey(),
    );
});

test('the catalog import operations page is restricted to verified super administrators', function () {
    $this->actingAs(User::factory()->create())
        ->get(CatalogImportResource::getUrl())
        ->assertForbidden();

    $this->actingAs(catalogImportAdmin())
        ->get(CatalogImportResource::getUrl())
        ->assertOk()
        ->assertSeeText('Catalog imports')
        ->assertSeeText('Import catalog CSV');

    $this->get(CatalogImportRowResource::getUrl())
        ->assertOk()
        ->assertSeeText('Catalog import rows');
});
