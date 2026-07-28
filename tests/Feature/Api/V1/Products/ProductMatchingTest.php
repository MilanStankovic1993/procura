<?php

use App\Actions\Analyses\CreateBuyAnalysisDraft;
use App\Actions\Analyses\RunBuyAnalysis;
use App\Actions\Analyses\SubmitAnalysis;
use App\Actions\Listings\CreateListing;
use App\Actions\Markets\SyncMarketReferenceData;
use App\Enums\Analyses\AnalysisStatus;
use App\Enums\Catalog\ProductMatchReviewStatus;
use App\Enums\Catalog\ProductMatchStatus;
use App\Enums\Listings\ListingImageKind;
use App\Enums\Organizations\OrganizationRole;
use App\Models\Analysis;
use App\Models\Brand;
use App\Models\Listing;
use App\Models\ListingImage;
use App\Models\MarketplaceSource;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProductAlias;
use App\Models\ProductCategory;
use App\Models\ProductMatch;
use App\Models\ProductModel;
use App\Models\ProductVariant;
use App\Models\ProductVariantMarket;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    app(SyncMarketReferenceData::class)->sync();
    $this->seed(PlanSeeder::class);
    Queue::fake();
});

function productMatchingWorkspace(): array
{
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    OrganizationMembership::factory()->create([
        'organization_id' => $organization,
        'user_id' => $user,
        'role' => OrganizationRole::Owner,
    ]);
    $user->update(['current_organization_id' => $organization->getKey()]);

    return [$user, $organization];
}

function productMatchingListing(
    User $user,
    Organization $organization,
    string $title,
    string $targetCountryCode = 'DE',
): Listing {
    $listing = app(CreateListing::class)->create(
        $organization,
        $user,
        MarketplaceSource::query()->where('key', 'manual')->firstOrFail(),
        [
            'source_url' => 'https://market.example/listings/product-match',
            'external_id' => str($title)->slug()->limit(100)->toString(),
            'marketplace_name' => 'Golden Catalog Market',
            'title' => $title,
            'description' => 'Complete professional tool kit with charger and hard case.',
            'asking_price_minor' => 18999,
            'currency_code' => 'EUR',
            'seller_information' => 'Private seller.',
            'location' => 'Vienna',
            'source_country_code' => 'AT',
            'target_country_code' => $targetCountryCode,
            'status' => 'active',
            'notes' => null,
        ],
    );
    ListingImage::query()->create([
        'listing_id' => $listing->getKey(),
        'uploaded_by_user_id' => $user->getKey(),
        'kind' => ListingImageKind::Product,
        'disk' => 'local',
        'path' => "testing/{$listing->getKey()}/product.png",
        'client_filename' => 'product.png',
        'mime_type' => 'image/png',
        'extension' => 'png',
        'size_bytes' => 2048,
        'width' => 800,
        'height' => 600,
        'checksum_sha256' => str_repeat('b', 64),
        'position' => 1,
    ]);

    return $listing;
}

function productMatchingRun(
    User $user,
    Organization $organization,
    Listing $listing,
): Analysis {
    $analysis = app(CreateBuyAnalysisDraft::class)->create(
        $organization,
        $user,
        $listing->getKey(),
        $listing->target_country_code,
    );
    $analysis = app(SubmitAnalysis::class)->submit(
        $organization,
        $user,
        $analysis->getKey(),
    );
    app(RunBuyAnalysis::class)->run(
        $analysis->getKey(),
        $analysis->currentDispatch()->valueOrFail('id'),
    );

    return $analysis->fresh();
}

function productMatchingCatalog(): array
{
    return [
        ProductCategory::query()->create([
            'name' => 'Cordless Drills',
            'slug' => 'cordless-drills',
        ]),
        Brand::query()->create(['name' => 'Bosch Professional']),
    ];
}

function productMatchingModel(
    ProductCategory $category,
    Brand $brand,
    string $name,
    string $modelNumber,
    string $canonicalKey,
): ProductModel {
    return ProductModel::query()->create([
        'brand_id' => $brand->getKey(),
        'product_category_id' => $category->getKey(),
        'name' => $name,
        'model_number' => $modelNumber,
        'canonical_key' => $canonicalKey,
    ]);
}

test('an exact catalog alias creates one explainable idempotent product match', function () {
    [$owner, $organization] = productMatchingWorkspace();
    [$category, $brand] = productMatchingCatalog();
    $model = productMatchingModel(
        $category,
        $brand,
        'GSR 18V-55',
        'GSR 18V-55',
        'bosch-professional:gsr-18v-55',
    );
    ProductAlias::query()->create([
        'product_model_id' => $model->getKey(),
        'alias' => 'GSR 18V-55',
        'source' => 'golden_test',
    ]);
    $listing = productMatchingListing(
        $owner,
        $organization,
        'Bosch Professional GSR 18V-55 cordless drill',
    );
    $analysis = productMatchingRun($owner, $organization, $listing);
    $dispatchId = $analysis->currentDispatch()->valueOrFail('id');

    app(RunBuyAnalysis::class)->run($analysis->getKey(), $dispatchId);

    $match = ProductMatch::query()->firstOrFail();
    expect($analysis->status)->toBe(AnalysisStatus::NeedsInput)
        ->and($analysis->result_payload['needs_input'])
        ->toContain('no_comparable_records')
        ->and($match->status)->toBe(ProductMatchStatus::Matched)
        ->and($match->review_status)->toBe(ProductMatchReviewStatus::NotRequired)
        ->and($match->product_model_id)->toBe($model->getKey())
        ->and($match->candidate_snapshot)->toHaveCount(1)
        ->and($match->candidate_snapshot[0]['matched_alias'])->toBe('GSR 18V-55')
        ->and(ProductMatch::query()->count())->toBe(1);

    $this->actingAs($owner)
        ->getJson(route('api.v1.analyses.show', $analysis))
        ->assertOk()
        ->assertJsonPath('data.product_match.status', 'matched')
        ->assertJsonPath('data.product_match.product.brand', 'Bosch Professional')
        ->assertJsonPath('data.product_match.product.model', 'GSR 18V-55')
        ->assertJsonPath('data.product_match.review_status', 'not_required');

    [$outsider] = productMatchingWorkspace();
    $this->actingAs($outsider)
        ->getJson(route('api.v1.analyses.show', $analysis))
        ->assertNotFound();
});

test('an unknown product remains explicitly unmatched and requests model input', function () {
    [$owner, $organization] = productMatchingWorkspace();
    $listing = productMatchingListing(
        $owner,
        $organization,
        'Unknown ZXQ-991 professional tool',
    );
    $analysis = productMatchingRun($owner, $organization, $listing);
    $match = ProductMatch::query()->firstOrFail();

    expect($analysis->status)->toBe(AnalysisStatus::NeedsInput)
        ->and($analysis->result_payload['needs_input'])->toContain('model_uncertain')
        ->and($match->status)->toBe(ProductMatchStatus::Unmatched)
        ->and($match->review_status)->toBe(ProductMatchReviewStatus::Pending)
        ->and($match->product_model_id)->toBeNull()
        ->and($match->product_variant_id)->toBeNull()
        ->and(ProductModel::query()->count())->toBe(0);
});

test('ambiguous aliases preserve candidates without silently selecting a model', function () {
    [$owner, $organization] = productMatchingWorkspace();
    [$category, $brand] = productMatchingCatalog();
    $otherBrand = Brand::query()->create(['name' => 'Makita']);
    $first = productMatchingModel(
        $category,
        $brand,
        'Universal 18V Drill A',
        'U18-A',
        'bosch-professional:u18-a',
    );
    $second = productMatchingModel(
        $category,
        $otherBrand,
        'Universal 18V Drill B',
        'U18-B',
        'makita:u18-b',
    );

    foreach ([$first, $second] as $model) {
        ProductAlias::query()->create([
            'product_model_id' => $model->getKey(),
            'alias' => 'Universal 18V Drill',
            'source' => 'golden_test',
        ]);
    }

    $listing = productMatchingListing(
        $owner,
        $organization,
        'Universal 18V Drill',
    );
    $analysis = productMatchingRun($owner, $organization, $listing);
    $match = ProductMatch::query()->firstOrFail();

    expect($analysis->status)->toBe(AnalysisStatus::NeedsInput)
        ->and($analysis->result_payload['needs_input'])
        ->toContain('model_confirmation_required')
        ->and($match->status)->toBe(ProductMatchStatus::ReviewRequired)
        ->and($match->method)->toBe('ambiguous_exact_alias')
        ->and($match->product_model_id)->toBeNull()
        ->and($match->candidate_snapshot)->toHaveCount(2)
        ->and($match->reason_codes)->toBe(['multiple_close_catalog_candidates']);
});

test('a region incompatible variant requires review and preserves its market evidence', function () {
    [$owner, $organization] = productMatchingWorkspace();
    [$category, $brand] = productMatchingCatalog();
    $model = productMatchingModel(
        $category,
        $brand,
        'GSR 18V-55',
        'GSR 18V-55',
        'bosch-professional:gsr-18v-55',
    );
    $variant = ProductVariant::query()->create([
        'product_model_id' => $model->getKey(),
        'name' => 'EU 18V kit',
        'canonical_key' => 'bosch-professional:gsr-18v-55:eu-kit',
        'sku' => '06019H5202',
    ]);
    ProductVariantMarket::query()->create([
        'product_variant_id' => $variant->getKey(),
        'country_code' => 'DE',
        'market_model_number' => '06019H5202',
        'voltage_millivolts' => 18000,
        'plug_type' => 'CEE 7/16',
        'measurement_system' => 'metric',
        'warranty_applicable' => true,
        'included_accessories' => ['charger', 'case'],
    ]);
    ProductAlias::query()->create([
        'product_model_id' => $model->getKey(),
        'product_variant_id' => $variant->getKey(),
        'alias' => 'GSR 18V 55 EU kit',
        'country_code' => 'AT',
        'source' => 'golden_test',
    ]);
    $listing = productMatchingListing(
        $owner,
        $organization,
        'Bosch Professional GSR 18V 55 EU kit',
        'US',
    );
    $analysis = productMatchingRun($owner, $organization, $listing);
    $match = ProductMatch::query()->firstOrFail();

    expect($analysis->status)->toBe(AnalysisStatus::NeedsInput)
        ->and($analysis->result_payload['needs_input'])
        ->toContain('region_incompatible_variant')
        ->and($match->status)->toBe(ProductMatchStatus::ReviewRequired)
        ->and($match->method)->toBe('exact_alias_region_incompatible')
        ->and($match->product_model_id)->toBe($model->getKey())
        ->and($match->product_variant_id)->toBe($variant->getKey())
        ->and($match->candidate_snapshot[0]['region_compatibility'])
        ->toBe('incompatible');
});

test('global catalog search is authenticated validated and hard bounded', function () {
    [$owner] = productMatchingWorkspace();
    [$category, $brand] = productMatchingCatalog();

    for ($index = 1; $index <= 25; $index++) {
        productMatchingModel(
            $category,
            $brand,
            sprintf('Drill %02d', $index),
            sprintf('DRILL-%02d', $index),
            sprintf('bosch-professional:drill-%02d', $index),
        );
    }

    $model = ProductModel::query()->orderBy('canonical_key')->firstOrFail();
    $this->getJson(route('api.v1.products.search', ['q' => 'drill']))
        ->assertUnauthorized();
    $this->actingAs($owner)
        ->getJson(route('api.v1.products.search', ['q' => 'd']))
        ->assertUnprocessable();
    $this->actingAs($owner)
        ->getJson(route('api.v1.products.search', ['q' => '--']))
        ->assertUnprocessable();
    $this->actingAs($owner)
        ->getJson(route('api.v1.products.search', [
            'q' => 'drill',
            'per_page' => 50,
        ]))
        ->assertUnprocessable();
    $this->actingAs($owner)
        ->getJson(route('api.v1.products.search', [
            'q' => 'drill',
            'country_code' => 'DE',
            'per_page' => 20,
        ]))
        ->assertOk()
        ->assertJsonCount(20, 'data')
        ->assertJsonPath('meta.limit', 20)
        ->assertJsonPath('meta.country_code', 'DE');
    $this->actingAs($owner)
        ->getJson(route('api.v1.products.show', $model))
        ->assertOk()
        ->assertJsonPath('data.brand.name', 'Bosch Professional')
        ->assertJsonPath('data.category.slug', 'cordless-drills');
});
