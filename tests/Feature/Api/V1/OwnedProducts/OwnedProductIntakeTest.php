<?php

use App\Actions\Markets\SyncMarketReferenceData;
use App\Enums\Organizations\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\OwnedProduct;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    app(SyncMarketReferenceData::class)->sync();
});

function ownedProductWorkspace(
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

function ownedProductCategory(): ProductCategory
{
    return ProductCategory::query()->firstOrCreate(
        ['slug' => 'cordless-drills'],
        ['name' => 'Cordless drills', 'active' => true],
    );
}

function validOwnedProductPayload(array $overrides = []): array
{
    return [
        'product_category_id' => ownedProductCategory()->getKey(),
        'brand_name' => 'Bosch Professional',
        'model_name' => 'GSR 18V-55',
        'condition' => 'used_good',
        'age_months' => 0,
        'accessories' => null,
        'defects' => [],
        'purchase_history_known' => false,
        'purchase_history' => null,
        'target_continent_code' => 'EU',
        'target_country_codes' => ['DE', 'AT'],
        'cross_border_preference' => 'cross_border_allowed',
        'desired_sale_speed' => 'balanced',
        'status' => 'draft',
        'notes' => 'Confirm the serial label before analysis.',
        ...$overrides,
    ];
}

test('analysts create tenant owned products with exact unknown facts and immutable snapshots', function () {
    [$analyst, $organization] = ownedProductWorkspace(OrganizationRole::Analyst);

    $response = $this->actingAs($analyst)
        ->postJson(route('api.v1.owned-products.store'), validOwnedProductPayload())
        ->assertCreated()
        ->assertJsonPath('data.age_months', 0)
        ->assertJsonPath('data.accessories', null)
        ->assertJsonPath('data.defects', [])
        ->assertJsonPath('data.target_country_codes', ['DE', 'AT'])
        ->assertJsonPath('data.snapshot_count', 1);

    $ownedProduct = OwnedProduct::query()->findOrFail($response->json('data.id'));
    $snapshot = $ownedProduct->snapshots()->firstOrFail();

    expect($ownedProduct->organization_id)->toBe($organization->getKey())
        ->and($ownedProduct->created_by_user_id)->toBe($analyst->getKey())
        ->and($ownedProduct->raw_input['target_country_codes'])->toBe(['DE', 'AT'])
        ->and($snapshot->sequence)->toBe(1)
        ->and($snapshot->age_months)->toBe(0)
        ->and($snapshot->accessories)->toBeNull()
        ->and($snapshot->defects)->toBe([])
        ->and($snapshot->content_hash)->toHaveLength(64);

    expect(fn () => $snapshot->update(['notes' => 'forged']))
        ->toThrow(LogicException::class);
    expect(fn () => $snapshot->delete())->toThrow(LogicException::class);
});

test('owned product index and detail never cross the active tenant boundary', function () {
    [$owner, $organization] = ownedProductWorkspace();
    [$outsider, $outsideOrganization] = ownedProductWorkspace();

    $visibleId = $this->actingAs($owner)
        ->postJson(route('api.v1.owned-products.store'), validOwnedProductPayload())
        ->assertCreated()
        ->json('data.id');
    $outside = OwnedProduct::factory()->create([
        'organization_id' => $outsideOrganization,
        'created_by_user_id' => $outsider,
        'brand_name' => 'Private outside brand',
    ]);

    $this->actingAs($owner)
        ->getJson(route('api.v1.owned-products.index', ['q' => 'Bosch']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $visibleId)
        ->assertJsonMissing(['brand_name' => 'Private outside brand']);

    $this->actingAs($owner)
        ->getJson(route('api.v1.owned-products.show', $visibleId))
        ->assertOk()
        ->assertJsonPath('data.id', $visibleId)
        ->assertJsonCount(1, 'data.snapshots');

    $this->actingAs($owner)
        ->getJson(route('api.v1.owned-products.show', $outside))
        ->assertNotFound();
});

test('viewers can inspect owned products but cannot create update or manage images', function () {
    [$owner, $organization] = ownedProductWorkspace();
    [$viewer] = ownedProductWorkspace(OrganizationRole::Viewer);
    OrganizationMembership::query()
        ->where('user_id', $viewer->getKey())
        ->update(['organization_id' => $organization->getKey()]);
    $viewer->update(['current_organization_id' => $organization->getKey()]);

    $ownedProductId = $this->actingAs($owner)
        ->postJson(route('api.v1.owned-products.store'), validOwnedProductPayload())
        ->assertCreated()
        ->json('data.id');
    $ownedProduct = OwnedProduct::query()->findOrFail($ownedProductId);

    expect(Gate::forUser($viewer)->allows('view', $ownedProduct))->toBeTrue()
        ->and(Gate::forUser($viewer)->allows('update', $ownedProduct))->toBeFalse();

    $this->actingAs($viewer)
        ->getJson(route('api.v1.owned-products.show', $ownedProduct))
        ->assertOk();
    $this->actingAs($viewer)
        ->postJson(route('api.v1.owned-products.store'), validOwnedProductPayload())
        ->assertForbidden();
    $this->actingAs($viewer)
        ->patchJson(route('api.v1.owned-products.update', $ownedProduct), [
            'notes' => 'forged',
        ])
        ->assertForbidden();

    Storage::fake('local');
    $this->actingAs($viewer)
        ->post(route('api.v1.owned-products.images.store', $ownedProduct), [
            'kind' => 'product',
            'images' => [UploadedFile::fake()->image('drill.jpg', 800, 600)],
        ], ['Accept' => 'application/json'])
        ->assertForbidden();
});

test('owned product validation enforces references market scope and explicit unknowns', function () {
    [$owner] = ownedProductWorkspace();

    $this->actingAs($owner)
        ->postJson(route('api.v1.owned-products.store'), validOwnedProductPayload([
            'age_months' => 1201,
            'purchase_history_known' => false,
            'purchase_history' => 'Contradictory known history.',
            'target_country_codes' => ['DE', 'US', 'DE'],
            'status' => 'archived',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'age_months',
            'purchase_history',
            'target_country_codes',
            'status',
        ]);
});

test('updates append only changed snapshots preserve original input and target order', function () {
    [$owner] = ownedProductWorkspace();
    $ownedProductId = $this->actingAs($owner)
        ->postJson(route('api.v1.owned-products.store'), validOwnedProductPayload())
        ->assertCreated()
        ->json('data.id');

    $update = [
        'age_months' => 12,
        'accessories' => [],
        'target_continent_code' => 'EU',
        'target_country_codes' => ['AT', 'DE'],
        'status' => 'ready',
    ];

    $this->actingAs($owner)
        ->patchJson(route('api.v1.owned-products.update', $ownedProductId), $update)
        ->assertOk()
        ->assertJsonPath('data.age_months', 12)
        ->assertJsonPath('data.accessories', [])
        ->assertJsonPath('data.target_country_codes', ['AT', 'DE'])
        ->assertJsonPath('data.snapshot_count', 2);

    $this->actingAs($owner)
        ->patchJson(route('api.v1.owned-products.update', $ownedProductId), $update)
        ->assertOk()
        ->assertJsonPath('data.snapshot_count', 2);

    $ownedProduct = OwnedProduct::query()->findOrFail($ownedProductId);

    expect($ownedProduct->raw_input['age_months'])->toBe(0)
        ->and($ownedProduct->snapshots()->count())->toBe(2)
        ->and($ownedProduct->snapshots()->reorder('sequence')->get()[1]
            ->target_country_codes)->toBe(['AT', 'DE']);
});

test('archiving is terminal for facts and private images', function () {
    Storage::fake('local');
    [$owner] = ownedProductWorkspace();
    $ownedProductId = $this->actingAs($owner)
        ->postJson(route('api.v1.owned-products.store'), validOwnedProductPayload())
        ->assertCreated()
        ->json('data.id');

    $this->actingAs($owner)
        ->patchJson(route('api.v1.owned-products.update', $ownedProductId), [
            'status' => 'archived',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'archived');

    $this->actingAs($owner)
        ->patchJson(route('api.v1.owned-products.update', $ownedProductId), [
            'status' => 'draft',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');

    $this->actingAs($owner)
        ->post(route('api.v1.owned-products.images.store', $ownedProductId), [
            'kind' => 'product',
            'images' => [UploadedFile::fake()->image('drill.jpg', 800, 600)],
        ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');
});

test('private images require validation authorization and signed access', function () {
    Storage::fake('local');
    [$owner] = ownedProductWorkspace();
    $ownedProductId = $this->actingAs($owner)
        ->postJson(route('api.v1.owned-products.store'), validOwnedProductPayload())
        ->assertCreated()
        ->json('data.id');

    $upload = $this->actingAs($owner)
        ->post(route('api.v1.owned-products.images.store', $ownedProductId), [
            'kind' => 'serial_label',
            'images' => [
                UploadedFile::fake()->image('../Unsafe serial.JPG', 800, 600)->size(500),
            ],
        ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.0.kind', 'serial_label')
        ->assertJsonPath('data.0.filename', 'Unsafe-serial.jpg');

    $imageId = $upload->json('data.0.id');
    $contentUrl = $upload->json('data.0.content_url');
    $image = OwnedProduct::query()
        ->findOrFail($ownedProductId)
        ->images()
        ->findOrFail($imageId);

    expect(Storage::disk('local')->exists($image->path))->toBeTrue();

    $this->actingAs($owner)
        ->get($contentUrl)
        ->assertOk()
        ->assertHeader('content-type', 'image/jpeg')
        ->assertHeader('x-content-type-options', 'nosniff');
    $this->actingAs($owner)
        ->get(route('api.v1.owned-product-images.content', $image))
        ->assertForbidden();
    $this->actingAs($owner)
        ->deleteJson(route('api.v1.owned-products.images.destroy', [
            $ownedProductId,
            $imageId,
        ]))
        ->assertNoContent();

    expect(Storage::disk('local')->exists($image->path))->toBeFalse();
});

test('failed owned product image batches roll back records and private files', function () {
    Storage::fake('local');
    config()->set('owned_products.uploads.max_product_images', 1);
    [$owner] = ownedProductWorkspace();
    $ownedProductId = $this->actingAs($owner)
        ->postJson(route('api.v1.owned-products.store'), validOwnedProductPayload())
        ->assertCreated()
        ->json('data.id');

    $this->actingAs($owner)
        ->post(route('api.v1.owned-products.images.store', $ownedProductId), [
            'kind' => 'product',
            'images' => [
                UploadedFile::fake()->image('one.jpg', 800, 600),
                UploadedFile::fake()->image('two.jpg', 800, 600),
            ],
        ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('images');

    expect(OwnedProduct::query()->findOrFail($ownedProductId)->images()->count())
        ->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

test('product categories are bounded active catalog references', function () {
    [$owner] = ownedProductWorkspace();
    ownedProductCategory();
    ProductCategory::query()->create([
        'name' => 'Inactive category',
        'slug' => 'inactive-category',
        'active' => false,
    ]);

    $this->actingAs($owner)
        ->getJson(route('api.v1.product-categories.index'))
        ->assertOk()
        ->assertJsonFragment(['slug' => 'cordless-drills'])
        ->assertJsonMissing(['slug' => 'inactive-category']);
});

test('unverified and unauthenticated users cannot access owned product intake', function () {
    [$user] = ownedProductWorkspace();
    $user->forceFill(['email_verified_at' => null])->save();

    $this->getJson(route('api.v1.owned-products.index'))
        ->assertUnauthorized();
    $this->actingAs($user)
        ->getJson(route('api.v1.owned-products.index'))
        ->assertForbidden();
});
