<?php

use App\Actions\Markets\SyncMarketReferenceData;
use App\Enums\Listings\ListingStatus;
use App\Enums\Organizations\OrganizationRole;
use App\Models\Listing;
use App\Models\MarketplaceSource;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    app(SyncMarketReferenceData::class)->sync();
});

function listingWorkspace(OrganizationRole $role = OrganizationRole::Owner): array
{
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

function validListingPayload(array $overrides = []): array
{
    return [
        'marketplace_source_key' => 'manual',
        'source_url' => 'https://market.example/listings/drill-42',
        'external_id' => 'drill-42',
        'marketplace_name' => 'Market Example',
        'title' => 'Bosch Professional GSB 18V-55',
        'description' => 'Used drill with two batteries and charger.',
        'asking_price_minor' => 12999,
        'currency_code' => 'EUR',
        'seller_information' => 'Private seller, identity not yet verified.',
        'location' => 'Vienna',
        'source_country_code' => 'AT',
        'target_country_code' => 'DE',
        'status' => 'active',
        'notes' => 'Confirm serial number before purchase.',
        ...$overrides,
    ];
}

test('analysts can create a tenant listing with an immutable original snapshot', function () {
    [$analyst, $organization] = listingWorkspace(OrganizationRole::Analyst);

    $response = $this->actingAs($analyst)
        ->postJson(route('api.v1.listings.store'), validListingPayload())
        ->assertCreated()
        ->assertJsonPath('data.marketplace_source.key', 'manual')
        ->assertJsonPath('data.asking_price_minor', 12999)
        ->assertJsonPath('data.snapshot_count', 1);

    $listing = Listing::query()->findOrFail($response->json('data.id'));
    $snapshot = $listing->snapshots()->firstOrFail();

    expect($listing->organization_id)->toBe($organization->getKey())
        ->and($listing->created_by_user_id)->toBe($analyst->getKey())
        ->and($listing->raw_input['asking_price_minor'])->toBe(12999)
        ->and($snapshot->sequence)->toBe(1)
        ->and($snapshot->asking_price_minor)->toBe(12999)
        ->and($snapshot->content_hash)->toHaveLength(64);
});

test('listing index and detail are bounded to the active organization', function () {
    [$owner, $organization] = listingWorkspace();
    [$outsider, $outsideOrganization] = listingWorkspace();
    $source = MarketplaceSource::query()->where('key', 'manual')->firstOrFail();

    $visible = Listing::factory()->create([
        'organization_id' => $organization,
        'marketplace_source_id' => $source,
        'created_by_user_id' => $owner,
        'title' => 'Visible impact driver',
        'external_id' => 'visible-1',
    ]);
    Listing::factory()->create([
        'organization_id' => $outsideOrganization,
        'marketplace_source_id' => $source,
        'created_by_user_id' => $outsider,
        'title' => 'Private outside listing',
        'external_id' => 'outside-1',
    ]);

    $this->actingAs($owner)
        ->getJson(route('api.v1.listings.index', ['q' => 'impact']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $visible->getKey())
        ->assertJsonMissing(['title' => 'Private outside listing']);

    $this->actingAs($owner)
        ->getJson(route('api.v1.listings.show', $visible))
        ->assertOk()
        ->assertJsonPath('data.id', $visible->getKey());

    $this->actingAs($outsider)
        ->getJson(route('api.v1.listings.show', $visible))
        ->assertNotFound();

    $this->actingAs($outsider)
        ->patchJson(route('api.v1.listings.update', $visible), ['status' => 'sold'])
        ->assertNotFound();
});

test('viewers can read listings but cannot create update or manage images', function () {
    [$owner, $organization] = listingWorkspace();
    [$viewer] = listingWorkspace(OrganizationRole::Viewer);
    OrganizationMembership::query()
        ->where('user_id', $viewer->getKey())
        ->update(['organization_id' => $organization->getKey()]);
    $viewer->update(['current_organization_id' => $organization->getKey()]);
    $source = MarketplaceSource::query()->where('key', 'manual')->firstOrFail();
    $listing = Listing::factory()->create([
        'organization_id' => $organization,
        'marketplace_source_id' => $source,
        'created_by_user_id' => $owner,
    ]);

    expect(Gate::forUser($viewer)->allows('view', $listing))->toBeTrue()
        ->and(Gate::forUser($viewer)->allows('update', $listing))->toBeFalse();

    $this->actingAs($viewer)
        ->getJson(route('api.v1.listings.show', $listing))
        ->assertOk();

    $this->actingAs($viewer)
        ->postJson(route('api.v1.listings.store'), validListingPayload([
            'external_id' => 'viewer-forgery',
        ]))
        ->assertForbidden();

    $this->actingAs($viewer)
        ->patchJson(route('api.v1.listings.update', $listing), ['status' => 'sold'])
        ->assertForbidden();

    Storage::fake('local');
    $this->actingAs($viewer)
        ->post(route('api.v1.listings.images.store', $listing), [
            'kind' => 'product',
            'images' => [UploadedFile::fake()->image('drill.jpg', 800, 600)],
        ], ['Accept' => 'application/json'])
        ->assertForbidden();
});

test('listing input validates global references money pairing and source URLs', function () {
    [$owner] = listingWorkspace();

    $this->actingAs($owner)
        ->postJson(route('api.v1.listings.store'), validListingPayload([
            'source_url' => 'file:///etc/passwd',
            'source_country_code' => 'ZZ',
            'target_country_code' => 'XX',
            'currency_code' => null,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'source_url',
            'source_country_code',
            'target_country_code',
            'currency_code',
        ]);
});

test('updates append snapshots without changing the original source facts', function () {
    [$owner] = listingWorkspace();
    $listingId = $this->actingAs($owner)
        ->postJson(route('api.v1.listings.store'), validListingPayload())
        ->assertCreated()
        ->json('data.id');

    $this->actingAs($owner)
        ->patchJson(route('api.v1.listings.update', $listingId), [
            'asking_price_minor' => 11999,
            'currency_code' => 'EUR',
            'status' => ListingStatus::Reserved->value,
            'notes' => 'Seller accepted a provisional reservation.',
        ])
        ->assertOk()
        ->assertJsonPath('data.asking_price_minor', 11999)
        ->assertJsonPath('data.status', 'reserved')
        ->assertJsonPath('data.snapshot_count', 2);

    $listing = Listing::query()->findOrFail($listingId);
    $snapshots = $listing->snapshots()->reorder('sequence')->get();

    expect($listing->raw_input['asking_price_minor'])->toBe(12999)
        ->and($snapshots)->toHaveCount(2)
        ->and($snapshots[0]->asking_price_minor)->toBe(12999)
        ->and($snapshots[1]->asking_price_minor)->toBe(11999);
});

test('private images require validation authorization and an unexpired signed URL', function () {
    Storage::fake('local');
    [$owner] = listingWorkspace();
    $listingId = $this->actingAs($owner)
        ->postJson(route('api.v1.listings.store'), validListingPayload())
        ->assertCreated()
        ->json('data.id');

    $upload = $this->actingAs($owner)
        ->post(route('api.v1.listings.images.store', $listingId), [
            'kind' => 'product',
            'images' => [
                UploadedFile::fake()->image('../Unsafe drill.JPG', 800, 600)->size(500),
            ],
        ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.0.kind', 'product')
        ->assertJsonPath('data.0.width', 800)
        ->assertJsonPath('data.0.height', 600);

    $imageId = $upload->json('data.0.id');
    $contentUrl = $upload->json('data.0.content_url');
    $image = Listing::query()->findOrFail($listingId)->images()->findOrFail($imageId);

    expect($image->client_filename)->toBe('Unsafe-drill.jpg')
        ->and(Storage::disk('local')->exists($image->path))->toBeTrue();

    $this->actingAs($owner)
        ->get($contentUrl)
        ->assertOk()
        ->assertHeader('content-type', 'image/jpeg')
        ->assertHeader('x-content-type-options', 'nosniff');

    $this->actingAs($owner)
        ->get(route('api.v1.listing-images.content', $image))
        ->assertForbidden();

    $this->actingAs($owner)
        ->deleteJson(route('api.v1.listings.images.destroy', [$listingId, $imageId]))
        ->assertNoContent();

    expect(Storage::disk('local')->exists($image->path))->toBeFalse();
});

test('failed image batches roll back database rows and private files', function () {
    Storage::fake('local');
    config()->set('listings.uploads.max_product_images', 1);
    [$owner] = listingWorkspace();
    $listingId = $this->actingAs($owner)
        ->postJson(route('api.v1.listings.store'), validListingPayload())
        ->assertCreated()
        ->json('data.id');

    $this->actingAs($owner)
        ->post(route('api.v1.listings.images.store', $listingId), [
            'kind' => 'product',
            'images' => [
                UploadedFile::fake()->image('one.jpg', 800, 600),
                UploadedFile::fake()->image('two.jpg', 800, 600),
            ],
        ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('images');

    expect(Listing::query()->findOrFail($listingId)->images()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

test('unverified and unauthenticated users cannot access listing intake', function () {
    [$user] = listingWorkspace();
    $user->forceFill(['email_verified_at' => null])->save();

    $this->getJson(route('api.v1.listings.index'))
        ->assertUnauthorized();

    $this->actingAs($user)
        ->getJson(route('api.v1.listings.index'))
        ->assertForbidden();
});
