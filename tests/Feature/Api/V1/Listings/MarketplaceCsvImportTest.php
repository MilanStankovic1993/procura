<?php

use App\Actions\MarketplaceImports\ProcessMarketplaceImport;
use App\Actions\Markets\SyncMarketReferenceData;
use App\Enums\Listings\MarketplaceImportRowStatus;
use App\Enums\Listings\MarketplaceImportStatus;
use App\Enums\Organizations\OrganizationRole;
use App\Jobs\ProcessMarketplaceImport as ProcessMarketplaceImportJob;
use App\Models\Listing;
use App\Models\MarketplaceImport;
use App\Models\MarketplaceSource;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config(['marketplace_connectors.csv_import_enabled' => true]);
    app(SyncMarketReferenceData::class)->sync();
    Storage::fake('local');
    Queue::fake();
});

function marketplaceImportWorkspace(
    OrganizationRole $role = OrganizationRole::Owner,
): array {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    OrganizationMembership::factory()->create([
        'organization_id' => $organization,
        'user_id' => $user,
        'role' => $role,
    ]);
    $user->update(['current_organization_id' => $organization->getKey()]);

    return [$user, $organization];
}

function marketplaceImportCsv(): string
{
    return implode("\n", [
        'external_id,marketplace_name,title,asking_price_minor,currency_code,source_country_code,target_country_code,status,source_url',
        'csv-1,Authorized Market,Bosch GSB 18V-55,12999,EUR,AT,DE,active,https://market.example/csv-1',
        'csv-2,Authorized Market,Invalid currency,9999,ZZZ,AT,DE,active,https://market.example/csv-2',
        'csv-1,Authorized Market,Repeated source identity,12999,EUR,AT,DE,active,https://market.example/csv-1',
    ]);
}

function submitMarketplaceImport(
    $test,
    User $user,
    string $csv,
    string $idempotencyKey,
): TestResponse {
    return $test->actingAs($user)->post(
        route('api.v1.marketplace-imports.store'),
        [
            'marketplace_source_key' => MarketplaceSource::AUTHORIZED_CSV_KEY,
            'file' => UploadedFile::fake()->createWithContent('listings.csv', $csv),
            'delimiter' => 'comma',
            'authorization_confirmed' => '1',
        ],
        [
            'Accept' => 'application/json',
            'Idempotency-Key' => $idempotencyKey,
        ],
    );
}

test('authorized csv imports are queued and preserve private immutable row outcomes', function () {
    [$owner, $organization] = marketplaceImportWorkspace();

    $response = submitMarketplaceImport(
        $this,
        $owner,
        marketplaceImportCsv(),
        (string) Str::uuid(),
    )
        ->assertAccepted()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath(
            'data.marketplace_source.key',
            MarketplaceSource::AUTHORIZED_CSV_KEY,
        );

    $import = MarketplaceImport::query()->findOrFail($response->json('data.id'));
    Storage::disk('local')->assertExists($import->path);
    Queue::assertPushed(
        ProcessMarketplaceImportJob::class,
        fn (ProcessMarketplaceImportJob $job): bool => $job->importId === $import->getKey()
            && $job->queue === 'connectors',
    );

    app(ProcessMarketplaceImport::class)->process($import->getKey());
    $import->refresh();

    expect($import->organization_id)->toBe($organization->getKey())
        ->and($import->status)->toBe(MarketplaceImportStatus::CompletedWithErrors)
        ->and($import->total_rows)->toBe(3)
        ->and($import->processed_rows)->toBe(3)
        ->and($import->imported_rows)->toBe(1)
        ->and($import->rejected_rows)->toBe(1)
        ->and($import->duplicate_rows)->toBe(1)
        ->and($import->content_hash)->toHaveLength(64)
        ->and($import->authorization_confirmed_at)->not->toBeNull();

    $rows = $import->rows()->get();

    expect($rows)->toHaveCount(3)
        ->and($rows[0]->status)->toBe(MarketplaceImportRowStatus::Imported)
        ->and($rows[0]->listing_id)->not->toBeNull()
        ->and($rows[1]->status)->toBe(MarketplaceImportRowStatus::Rejected)
        ->and($rows[1]->validation_errors)->toHaveKey('currency_code')
        ->and($rows[2]->status)->toBe(MarketplaceImportRowStatus::Duplicate)
        ->and($rows[2]->listing_id)->toBe($rows[0]->listing_id);

    $listing = Listing::query()->findOrFail($rows[0]->listing_id);
    $snapshot = $listing->snapshots()->firstOrFail();

    expect($listing->organization_id)->toBe($organization->getKey())
        ->and($listing->marketplaceSource->key)
        ->toBe(MarketplaceSource::AUTHORIZED_CSV_KEY)
        ->and($snapshot->raw_payload['event'])->toBe('connector_import')
        ->and($snapshot->raw_payload['marketplace_import_id'])->toBe($import->getKey())
        ->and($snapshot->raw_payload['row_hash'])->toHaveLength(64);
});

test('csv import submission is idempotent by key and content', function () {
    [$owner] = marketplaceImportWorkspace();
    $key = (string) Str::uuid();

    $firstId = submitMarketplaceImport($this, $owner, marketplaceImportCsv(), $key)
        ->assertAccepted()
        ->json('data.id');

    submitMarketplaceImport($this, $owner, marketplaceImportCsv(), $key)
        ->assertAccepted()
        ->assertJsonPath('data.id', $firstId);

    submitMarketplaceImport(
        $this,
        $owner,
        marketplaceImportCsv(),
        (string) Str::uuid(),
    )
        ->assertAccepted()
        ->assertJsonPath('data.id', $firstId);

    expect(MarketplaceImport::query()->count())->toBe(1);

    submitMarketplaceImport(
        $this,
        $owner,
        str_replace('Bosch', 'Makita', marketplaceImportCsv()),
        $key,
    )
        ->assertConflict()
        ->assertJsonPath('code', 'idempotency_payload_mismatch');
});

test('marketplace imports are tenant scoped and viewers cannot submit files', function () {
    [$owner, $organization] = marketplaceImportWorkspace();
    [$outsideOwner] = marketplaceImportWorkspace();
    [$viewer] = marketplaceImportWorkspace(OrganizationRole::Viewer);
    $importId = submitMarketplaceImport(
        $this,
        $owner,
        marketplaceImportCsv(),
        (string) Str::uuid(),
    )->assertAccepted()->json('data.id');

    $this->actingAs($owner)
        ->getJson(route('api.v1.marketplace-imports.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $importId);

    $this->actingAs($owner)
        ->getJson(route('api.v1.marketplace-imports.show', $importId))
        ->assertOk()
        ->assertJsonPath('data.id', $importId);

    $this->actingAs($outsideOwner)
        ->getJson(route('api.v1.marketplace-imports.show', $importId))
        ->assertNotFound();

    submitMarketplaceImport(
        $this,
        $viewer,
        marketplaceImportCsv(),
        (string) Str::uuid(),
    )->assertForbidden();

    expect(
        MarketplaceImport::query()
            ->where('organization_id', $organization->getKey())
            ->count(),
    )->toBe(1);
});

test('malformed imports fail closed without creating partial listings', function () {
    [$owner] = marketplaceImportWorkspace();
    $importId = submitMarketplaceImport(
        $this,
        $owner,
        "title,source_country_code\nMissing identity,AT",
        (string) Str::uuid(),
    )->assertAccepted()->json('data.id');

    expect(
        fn () => app(ProcessMarketplaceImport::class)->process($importId),
    )->toThrow(InvalidArgumentException::class);

    $import = MarketplaceImport::query()->findOrFail($importId);

    expect($import->status)->toBe(MarketplaceImportStatus::Failed)
        ->and($import->processed_rows)->toBe(0)
        ->and($import->last_error_message)->toContain('csv_required_headers_missing')
        ->and(Listing::query()->count())->toBe(0);
});

test('csv connector cannot be forged through the manual listing endpoint', function () {
    [$owner] = marketplaceImportWorkspace();

    $this->actingAs($owner)
        ->postJson(route('api.v1.listings.store'), [
            'marketplace_source_key' => MarketplaceSource::AUTHORIZED_CSV_KEY,
            'external_id' => 'forged',
            'marketplace_name' => 'Forged market',
            'title' => 'Forged listing',
            'source_country_code' => 'AT',
            'target_country_code' => 'DE',
            'status' => 'active',
        ])
        ->assertNotFound();
});

test('semicolon imports accept a utf8 bom and an explicit default target market', function () {
    [$owner] = marketplaceImportWorkspace();
    $csv = "\xEF\xBB\xBFexternal_id;marketplace_name;title;source_country_code;status\n"
        .'semi-1;Authorized Market;Makita drill;at;ACTIVE';
    $response = $this->actingAs($owner)->post(
        route('api.v1.marketplace-imports.store'),
        [
            'file' => UploadedFile::fake()->createWithContent('listings.csv', $csv),
            'delimiter' => 'semicolon',
            'default_target_country_code' => 'de',
            'authorization_confirmed' => '1',
        ],
        [
            'Accept' => 'application/json',
            'Idempotency-Key' => (string) Str::uuid(),
        ],
    )->assertAccepted();

    app(ProcessMarketplaceImport::class)->process($response->json('data.id'));

    $listing = Listing::query()->where('external_id', 'semi-1')->firstOrFail();

    expect($listing->source_country_code)->toBe('AT')
        ->and($listing->target_country_code)->toBe('DE')
        ->and($listing->status->value)->toBe('active');
});

test('the csv production kill switch fails closed and is exposed as unavailable', function () {
    [$owner] = marketplaceImportWorkspace();
    config(['marketplace_connectors.csv_import_enabled' => false]);

    $this->actingAs($owner)
        ->getJson(route('api.v1.marketplace-sources.index'))
        ->assertOk()
        ->assertJsonFragment([
            'key' => MarketplaceSource::AUTHORIZED_CSV_KEY,
            'available' => false,
        ]);

    submitMarketplaceImport(
        $this,
        $owner,
        marketplaceImportCsv(),
        (string) Str::uuid(),
    )->assertNotFound();
});

test('the recovery command dispatches pending and stale imports without touching active work', function () {
    [$pendingOwner] = marketplaceImportWorkspace();
    [$staleOwner] = marketplaceImportWorkspace();
    [$activeOwner] = marketplaceImportWorkspace();

    $pendingId = submitMarketplaceImport(
        $this,
        $pendingOwner,
        marketplaceImportCsv(),
        (string) Str::uuid(),
    )->assertAccepted()->json('data.id');
    $staleId = submitMarketplaceImport(
        $this,
        $staleOwner,
        marketplaceImportCsv(),
        (string) Str::uuid(),
    )->assertAccepted()->json('data.id');
    $activeId = submitMarketplaceImport(
        $this,
        $activeOwner,
        marketplaceImportCsv(),
        (string) Str::uuid(),
    )->assertAccepted()->json('data.id');

    MarketplaceImport::query()->findOrFail($staleId)->update([
        'status' => MarketplaceImportStatus::Processing,
        'started_at' => now()->subSeconds(
            (int) config('marketplace_connectors.processing_timeout_seconds') + 1,
        ),
    ]);
    MarketplaceImport::query()->findOrFail($activeId)->update([
        'status' => MarketplaceImportStatus::Processing,
        'started_at' => now(),
    ]);

    Queue::fake();

    $this->artisan('marketplace-imports:dispatch-pending', ['--limit' => 10])
        ->expectsOutput('Dispatched 2 pending marketplace imports.')
        ->assertSuccessful();

    Queue::assertPushed(
        ProcessMarketplaceImportJob::class,
        fn (ProcessMarketplaceImportJob $job): bool => $job->importId === $pendingId,
    );
    Queue::assertPushed(
        ProcessMarketplaceImportJob::class,
        fn (ProcessMarketplaceImportJob $job): bool => $job->importId === $staleId,
    );
    Queue::assertNotPushed(
        ProcessMarketplaceImportJob::class,
        fn (ProcessMarketplaceImportJob $job): bool => $job->importId === $activeId,
    );
});
