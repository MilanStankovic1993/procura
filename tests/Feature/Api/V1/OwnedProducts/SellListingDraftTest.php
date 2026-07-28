<?php

use App\Actions\Markets\SyncMarketReferenceData;
use App\Enums\Organizations\OrganizationRole;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\OwnedProduct;
use App\Models\OwnedProductImage;
use App\Models\ProductAlias;
use App\Models\ProductCategory;
use App\Models\ProductModel;
use App\Models\SellListingDraft;
use App\Models\User;

beforeEach(function () {
    app(SyncMarketReferenceData::class)->sync();
});

function listingDraftWorkspace(
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

function listingDraftProduct($test, User $owner): OwnedProduct
{
    $category = ProductCategory::query()->create([
        'name' => 'Listing draft tools',
        'slug' => 'listing-draft-tools',
    ]);
    $brand = Brand::query()->create(['name' => 'Draft Tools']);
    $model = ProductModel::query()->create([
        'brand_id' => $brand->getKey(),
        'product_category_id' => $category->getKey(),
        'name' => 'DT 18V-200',
        'model_number' => 'DT 18V-200',
        'canonical_key' => 'draft-tools:dt-18v-200',
    ]);
    ProductAlias::query()->create([
        'product_model_id' => $model->getKey(),
        'alias' => 'DT 18V-200',
        'source' => 'listing_draft_test',
    ]);
    $productId = $test->actingAs($owner)
        ->postJson(route('api.v1.owned-products.store'), [
            'product_category_id' => $category->getKey(),
            'brand_name' => 'Draft Tools',
            'model_name' => 'DT 18V-200',
            'condition' => 'used_good',
            'age_months' => 18,
            'accessories' => [],
            'defects' => [],
            'purchase_history_known' => true,
            'purchase_history' => 'Receipt retained privately.',
            'target_continent_code' => 'EU',
            'target_country_codes' => ['DE'],
            'cross_border_preference' => 'cross_border_allowed',
            'desired_sale_speed' => 'balanced',
            'status' => 'ready',
            'notes' => null,
        ])
        ->assertCreated()
        ->json('data.id');
    $product = OwnedProduct::query()->findOrFail($productId);

    foreach (range(1, 3) as $position) {
        listingDraftImage(
            $product,
            $owner,
            'product',
            $position,
            1600,
            1200,
        );
    }
    listingDraftImage($product, $owner, 'serial_label', 1, 1400, 1000);

    $snapshot = $product->snapshots()->firstOrFail();
    $test->actingAs($owner)
        ->postJson(
            route('api.v1.owned-products.assessments.store', $product),
            ['owned_product_snapshot_id' => $snapshot->getKey()],
        )
        ->assertCreated()
        ->assertJsonPath('data.status', 'ready');

    return $product->fresh();
}

function listingDraftImage(
    OwnedProduct $product,
    User $owner,
    string $kind,
    int $position,
    int $width,
    int $height,
): OwnedProductImage {
    $identity = "{$kind}-{$position}-{$product->getKey()}";

    return OwnedProductImage::query()->create([
        'owned_product_id' => $product->getKey(),
        'uploaded_by_user_id' => $owner->getKey(),
        'kind' => $kind,
        'disk' => 'local',
        'path' => "test/{$identity}.jpg",
        'client_filename' => "{$identity}.jpg",
        'mime_type' => 'image/jpeg',
        'extension' => 'jpg',
        'size_bytes' => 100000,
        'width' => $width,
        'height' => $height,
        'checksum_sha256' => hash('sha256', $identity),
        'position' => $position,
    ]);
}

function listingDraftComparable(
    OwnedProduct $product,
    int $position,
    int $price,
): array {
    return [
        'owned_product_assessment_id' => (
            $product->assessments()->firstOrFail()->getKey()
        ),
        'marketplace_source_key' => 'manual',
        'source_url' => "https://draft-market.example/items/{$position}",
        'external_id' => "draft-evidence-{$position}",
        'marketplace_name' => 'Draft Market',
        'title' => "Draft Tools DT 18V-200 {$position}",
        'description' => 'Manual asking-price evidence.',
        'listing_type' => 'product',
        'condition_code' => 'used_good',
        'seller_type' => 'private',
        'asking_price_minor' => $price,
        'currency_code' => 'EUR',
        'country_code' => 'DE',
        'location' => 'Berlin',
        'included_accessories' => [],
        'missing_accessories' => [],
        'published_at' => null,
        'observed_at' => now()
            ->startOfSecond()
            ->subDays(4 - $position)
            ->toIso8601String(),
    ];
}

function listingDraftReadyBand($test, User $owner, OwnedProduct $product): string
{
    $bandId = '';

    foreach ([20000, 22000, 24000] as $index => $price) {
        $bandId = $test->actingAs($owner)
            ->postJson(
                route(
                    'api.v1.owned-products.comparables.store',
                    $product,
                ),
                listingDraftComparable($product, $index + 1, $price),
            )
            ->assertCreated()
            ->json('data.price_band.id');
    }

    return $bandId;
}

function listingDraftPayload(
    OwnedProduct $product,
    string $bandId,
    array $overrides = [],
): array {
    return [
        'owned_product_assessment_id' => (
            $product->assessments()->firstOrFail()->getKey()
        ),
        'sell_price_band_id' => $bandId,
        'target_country_code' => 'DE',
        'target_currency_code' => 'EUR',
        'price_strategy' => 'recommended',
        'target_asking_price_minor' => 22000,
        'listing_language' => 'en',
        'price_override_reason' => null,
        ...$overrides,
    ];
}

test('localized listing drafts are reproducible source-bound and immutable', function () {
    [$owner] = listingDraftWorkspace();
    $product = listingDraftProduct($this, $owner);
    $bandId = listingDraftReadyBand($this, $owner, $product);
    $titles = [];

    foreach (['en', 'de', 'es', 'fr', 'sr-Latn'] as $language) {
        $response = $this->actingAs($owner)->postJson(
            route('api.v1.owned-products.listing-drafts.store', $product),
            listingDraftPayload($product, $bandId, [
                'listing_language' => $language,
            ]),
        );
        $response
            ->assertCreated()
            ->assertJsonPath('meta.created', true)
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.photo_readiness_status', 'ready')
            ->assertJsonPath('data.photo_readiness_basis_points', 10000)
            ->assertJsonPath('data.target_asking_price_minor', 22000)
            ->assertJsonPath('data.selected_band.low_minor', 20000)
            ->assertJsonPath('data.selected_band.high_minor', 24000)
            ->assertJsonPath('data.listing_language', $language)
            ->assertJsonCount(7, 'data.facts')
            ->assertJsonCount(7, 'data.photo_checklist')
            ->assertJsonFragment([
                'fact_code' => 'target_asking_price',
                'is_unknown' => false,
            ]);
        $titles[] = $response->json('data.title');
    }

    expect(array_unique($titles))->toHaveCount(5);
    $this->actingAs($owner)
        ->postJson(
            route('api.v1.owned-products.listing-drafts.store', $product),
            listingDraftPayload($product, $bandId),
        )
        ->assertOk()
        ->assertJsonPath('meta.created', false)
        ->assertJsonPath('data.run_number', 1)
        ->assertJsonFragment([
            'check_code' => 'private_proof_exclusion',
            'status' => 'not_applicable',
        ]);
    $this->actingAs($owner)
        ->getJson(
            route('api.v1.owned-products.listing-drafts.index', $product),
        )
        ->assertOk()
        ->assertJsonPath('data.assessment_current', true)
        ->assertJsonCount(1, 'data.available_price_bands')
        ->assertJsonCount(5, 'data.current_drafts')
        ->assertJsonCount(5, 'data.drafts');

    $draft = SellListingDraft::query()->with([
        'facts',
        'photoChecklist',
    ])->firstOrFail();
    expect($draft->input_hash)->toHaveLength(64)
        ->and($draft->source_fact_identifiers)->toHaveCount(8)
        ->and($draft->description)->not->toContain(
            'Receipt retained privately.',
        )
        ->and($draft->warnings)
        ->toContain('asking_price_guidance_not_guarantee');
    expect(fn () => $draft->update(['run_number' => 99]))
        ->toThrow(LogicException::class);
    expect(fn () => $draft->delete())->toThrow(LogicException::class);
    expect(fn () => $draft->facts->firstOrFail()->delete())
        ->toThrow(LogicException::class);
    expect(fn () => $draft->photoChecklist->firstOrFail()->delete())
        ->toThrow(LogicException::class);
});

test('price overrides require an explicit reason and remain reviewable', function () {
    [$owner] = listingDraftWorkspace();
    $product = listingDraftProduct($this, $owner);
    $bandId = listingDraftReadyBand($this, $owner, $product);
    $outside = listingDraftPayload($product, $bandId, [
        'target_asking_price_minor' => 50000,
    ]);

    $this->actingAs($owner)
        ->postJson(
            route('api.v1.owned-products.listing-drafts.store', $product),
            $outside,
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('price_override_reason');
    $response = $this->actingAs($owner)
        ->postJson(
            route('api.v1.owned-products.listing-drafts.store', $product),
            [
                ...$outside,
                'price_override_reason' => (
                    'Manual premium requested after physical inspection.'
                ),
            ],
        )
        ->assertCreated()
        ->assertJsonPath('data.status', 'review_required')
        ->assertJsonPath('data.target_asking_price_minor', 50000);
    expect($response->json('data.warnings'))
        ->toContain('target_price_outside_selected_band')
        ->and($response->json('data.verification_actions'))
        ->toContain('review_target_price_override');

    expect(SellListingDraft::query()->count())->toBe(1);
});

test('draft projections reject stale upstream evidence and generator versions', function () {
    [$owner] = listingDraftWorkspace();
    $product = listingDraftProduct($this, $owner);
    $bandId = listingDraftReadyBand($this, $owner, $product);
    $payload = listingDraftPayload($product, $bandId);

    $this->actingAs($owner)
        ->postJson(
            route('api.v1.owned-products.listing-drafts.store', $product),
            $payload,
        )
        ->assertCreated();
    config([
        'sell_listing_content.generator_version' => (
            'deterministic-sell-listing-generator:v-next'
        ),
    ]);
    $this->actingAs($owner)
        ->getJson(
            route('api.v1.owned-products.listing-drafts.index', $product),
        )
        ->assertOk()
        ->assertJsonCount(0, 'data.current_drafts')
        ->assertJsonCount(1, 'data.drafts');
    config([
        'sell_listing_content.generator_version' => (
            'deterministic-sell-listing-generator:v1'
        ),
    ]);
    config([
        'sell_listing_content.photo_readiness.minimum_product_images' => 4,
    ]);
    $this->actingAs($owner)
        ->getJson(
            route('api.v1.owned-products.listing-drafts.index', $product),
        )
        ->assertOk()
        ->assertJsonCount(0, 'data.current_drafts');
    config([
        'sell_listing_content.photo_readiness.minimum_product_images' => 3,
    ]);
    $this->actingAs($owner)
        ->getJson(
            route('api.v1.owned-products.listing-drafts.index', $product),
        )
        ->assertOk()
        ->assertJsonCount(1, 'data.current_drafts');

    listingDraftImage($product, $owner, 'product', 4, 1600, 1200);
    $this->actingAs($owner)
        ->postJson(
            route('api.v1.owned-products.listing-drafts.store', $product),
            $payload,
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('owned_product_assessment_id');
    $this->actingAs($owner)
        ->getJson(
            route('api.v1.owned-products.listing-drafts.index', $product),
        )
        ->assertOk()
        ->assertJsonPath('data.assessment_current', false)
        ->assertJsonCount(0, 'data.available_price_bands')
        ->assertJsonCount(0, 'data.current_drafts')
        ->assertJsonCount(1, 'data.drafts');
});

test('listing drafts enforce tenant and role boundaries', function () {
    [$owner, $organization] = listingDraftWorkspace();
    [$viewer] = listingDraftWorkspace(OrganizationRole::Viewer);
    OrganizationMembership::query()
        ->where('user_id', $viewer->getKey())
        ->update(['organization_id' => $organization->getKey()]);
    $viewer->update(['current_organization_id' => $organization->getKey()]);
    [$outsider] = listingDraftWorkspace();
    $product = listingDraftProduct($this, $owner);
    $bandId = listingDraftReadyBand($this, $owner, $product);
    $payload = listingDraftPayload($product, $bandId);

    $this->actingAs($viewer)
        ->getJson(
            route('api.v1.owned-products.listing-drafts.index', $product),
        )
        ->assertOk();
    $this->actingAs($viewer)
        ->postJson(
            route('api.v1.owned-products.listing-drafts.store', $product),
            $payload,
        )
        ->assertForbidden();
    $this->actingAs($outsider)
        ->getJson(
            route('api.v1.owned-products.listing-drafts.index', $product),
        )
        ->assertNotFound();
    $this->actingAs($outsider)
        ->postJson(
            route('api.v1.owned-products.listing-drafts.store', $product),
            $payload,
        )
        ->assertNotFound();

    expect(SellListingDraft::query()->count())->toBe(0);
});
