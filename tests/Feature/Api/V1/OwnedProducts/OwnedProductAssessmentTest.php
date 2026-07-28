<?php

use App\Actions\Markets\SyncMarketReferenceData;
use App\Enums\Organizations\OrganizationRole;
use App\Enums\OwnedProducts\OwnedProductImageKind;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\OwnedProduct;
use App\Models\OwnedProductAssessment;
use App\Models\OwnedProductImage;
use App\Models\ProductAlias;
use App\Models\ProductCategory;
use App\Models\ProductModel;
use App\Models\ProductVariant;
use App\Models\ProductVariantMarket;
use App\Models\User;

beforeEach(function () {
    app(SyncMarketReferenceData::class)->sync();
});

function assessmentWorkspace(
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

function assessmentCategory(): ProductCategory
{
    return ProductCategory::query()->firstOrCreate(
        ['slug' => 'assessment-cordless-drills'],
        ['name' => 'Assessment cordless drills', 'active' => true],
    );
}

function assessmentPayload(array $overrides = []): array
{
    return [
        'product_category_id' => assessmentCategory()->getKey(),
        'brand_name' => 'Bosch Professional',
        'model_name' => 'GSR 18V-55',
        'condition' => 'used_good',
        'age_months' => 18,
        'accessories' => ['charger'],
        'defects' => [],
        'purchase_history_known' => false,
        'purchase_history' => null,
        'target_continent_code' => 'EU',
        'target_country_codes' => ['DE'],
        'cross_border_preference' => 'cross_border_allowed',
        'desired_sale_speed' => 'balanced',
        'status' => 'ready',
        'notes' => null,
        ...$overrides,
    ];
}

function assessmentCatalog(
    string $alias = 'GSR 18V-55',
    ?string $canonicalKey = null,
): array {
    $category = assessmentCategory();
    $brand = Brand::query()->firstOrCreate(
        ['normalized_name' => 'bosch professional'],
        ['name' => 'Bosch Professional', 'active' => true],
    );
    $model = ProductModel::query()->create([
        'brand_id' => $brand->getKey(),
        'product_category_id' => $category->getKey(),
        'name' => 'GSR 18V-55',
        'model_number' => 'GSR 18V-55',
        'canonical_key' => $canonicalKey ?? 'assessment:bosch:gsr-18v-55',
    ]);
    $variant = ProductVariant::query()->create([
        'product_model_id' => $model->getKey(),
        'name' => 'EU kit',
        'canonical_key' => ($canonicalKey ?? 'assessment:bosch:gsr-18v-55').':eu-kit',
        'sku' => 'ASSESS-06019H5202',
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
        'alias' => $alias,
        'source' => 'assessment_test',
    ]);

    return [$model, $variant];
}

function createAssessmentProduct($test, User $user, array $overrides = []): OwnedProduct
{
    $id = $test->actingAs($user)
        ->postJson(
            route('api.v1.owned-products.store'),
            assessmentPayload($overrides),
        )
        ->assertCreated()
        ->json('data.id');

    return OwnedProduct::query()->findOrFail($id);
}

function assessProduct($test, User $user, OwnedProduct $product, string $snapshotId)
{
    return $test->actingAs($user)->postJson(
        route('api.v1.owned-products.assessments.store', $product),
        ['owned_product_snapshot_id' => $snapshotId],
    );
}

test('an exact assessment is explainable complete and tied to immutable evidence', function () {
    [$owner] = assessmentWorkspace();
    [$model, $variant] = assessmentCatalog();
    $product = createAssessmentProduct($this, $owner);
    $snapshot = $product->snapshots()->firstOrFail();

    assessProduct($this, $owner, $product, $snapshot->getKey())
        ->assertCreated()
        ->assertJsonPath('data.run_number', 1)
        ->assertJsonPath('data.status', 'ready')
        ->assertJsonPath('data.matcher_status', 'matched')
        ->assertJsonPath('data.review_status', 'not_required')
        ->assertJsonPath('data.product.id', $model->getKey())
        ->assertJsonPath('data.product.variant_id', $variant->getKey())
        ->assertJsonPath('data.missing_accessories', ['case'])
        ->assertJsonPath('data.completeness_basis_points', 8500)
        ->assertJsonPath('data.reason_codes.0', 'exact_catalog_alias');

    $assessment = OwnedProductAssessment::query()->firstOrFail();

    expect($assessment->snapshot_content_hash)->toBe($snapshot->content_hash)
        ->and($assessment->image_evidence_hash)->toHaveLength(64)
        ->and($assessment->input_hash)->toHaveLength(64)
        ->and($assessment->input_snapshot['snapshot_id'])->toBe($snapshot->getKey())
        ->and($assessment->assessed_by_user_id)->toBe($owner->getKey());
    expect(fn () => $assessment->update(['run_number' => 99]))
        ->toThrow(LogicException::class);
    expect(fn () => $assessment->delete())->toThrow(LogicException::class);
});

test('assessment replay is idempotent and changed images or intake make history stale', function () {
    [$owner] = assessmentWorkspace();
    assessmentCatalog();
    $product = createAssessmentProduct($this, $owner);
    $firstSnapshot = $product->snapshots()->firstOrFail();

    $firstId = assessProduct($this, $owner, $product, $firstSnapshot->getKey())
        ->assertCreated()
        ->json('data.id');
    assessProduct($this, $owner, $product, $firstSnapshot->getKey())
        ->assertOk()
        ->assertJsonPath('data.id', $firstId);
    expect(OwnedProductAssessment::query()->count())->toBe(1);

    $this->actingAs($owner)
        ->getJson(route('api.v1.owned-products.show', $product))
        ->assertOk()
        ->assertJsonPath('data.current_assessment.id', $firstId);

    config(['owned_product_assessment.evaluator_version' => 'owned-product-assessor:v-next']);
    $this->actingAs($owner)
        ->getJson(route('api.v1.owned-products.show', $product))
        ->assertOk()
        ->assertJsonPath('data.current_assessment', null);
    config(['owned_product_assessment.evaluator_version' => 'owned-product-assessor:v1']);

    OwnedProductImage::query()->create([
        'owned_product_id' => $product->getKey(),
        'uploaded_by_user_id' => $owner->getKey(),
        'kind' => OwnedProductImageKind::Product,
        'disk' => 'local',
        'path' => 'testing/assessment/product.png',
        'client_filename' => 'product.png',
        'mime_type' => 'image/png',
        'extension' => 'png',
        'size_bytes' => 2048,
        'width' => 800,
        'height' => 600,
        'checksum_sha256' => str_repeat('a', 64),
        'position' => 1,
    ]);
    $this->actingAs($owner)
        ->getJson(route('api.v1.owned-products.show', $product))
        ->assertOk()
        ->assertJsonPath('data.current_assessment', null)
        ->assertJsonPath('data.assessment_count', 1);

    assessProduct($this, $owner, $product, $firstSnapshot->getKey())
        ->assertCreated()
        ->assertJsonPath('data.run_number', 2);

    $this->actingAs($owner)
        ->patchJson(route('api.v1.owned-products.update', $product), [
            'notes' => 'Serial label verified.',
        ])
        ->assertOk()
        ->assertJsonPath('data.snapshot_count', 2);
    $latestSnapshot = $product->snapshots()->firstOrFail();

    assessProduct($this, $owner, $product, $firstSnapshot->getKey())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('owned_product_snapshot_id');
    assessProduct($this, $owner, $product, $latestSnapshot->getKey())
        ->assertCreated()
        ->assertJsonPath('data.run_number', 3);

    expect(OwnedProductAssessment::query()->count())->toBe(3);
});

test('unknown catalog and evidence stay explicit without creating catalog products', function () {
    [$owner] = assessmentWorkspace();
    $product = createAssessmentProduct($this, $owner, [
        'brand_name' => null,
        'model_name' => null,
        'condition' => 'unknown',
        'accessories' => null,
        'defects' => null,
    ]);
    $snapshot = $product->snapshots()->firstOrFail();

    assessProduct($this, $owner, $product, $snapshot->getKey())
        ->assertCreated()
        ->assertJsonPath('data.status', 'needs_input')
        ->assertJsonPath('data.matcher_status', 'unmatched')
        ->assertJsonPath('data.product', null)
        ->assertJsonPath('data.completeness_basis_points', 1000)
        ->assertJsonFragment(['condition_unknown'])
        ->assertJsonFragment(['provide_identifying_model_evidence'])
        ->assertJsonFragment(['included_accessories']);

    expect(ProductModel::query()->count())->toBe(0);
});

test('ambiguous catalog candidates require review without selecting a product', function () {
    [$owner] = assessmentWorkspace();
    assessmentCatalog('Universal 18V Drill', 'assessment:bosch:universal-a');
    $category = assessmentCategory();
    $otherBrand = Brand::query()->create(['name' => 'Makita']);
    $otherModel = ProductModel::query()->create([
        'brand_id' => $otherBrand->getKey(),
        'product_category_id' => $category->getKey(),
        'name' => 'Universal 18V Drill B',
        'model_number' => 'U18-B',
        'canonical_key' => 'assessment:makita:universal-b',
    ]);
    ProductAlias::query()->create([
        'product_model_id' => $otherModel->getKey(),
        'alias' => 'Universal 18V Drill',
        'source' => 'assessment_test',
    ]);
    $product = createAssessmentProduct($this, $owner, [
        'brand_name' => null,
        'model_name' => 'Universal 18V Drill',
    ]);

    assessProduct(
        $this,
        $owner,
        $product,
        $product->snapshots()->firstOrFail()->getKey(),
    )
        ->assertCreated()
        ->assertJsonPath('data.status', 'review_required')
        ->assertJsonPath('data.matcher_status', 'review_required')
        ->assertJsonPath('data.product', null)
        ->assertJsonCount(2, 'data.candidates')
        ->assertJsonFragment(['multiple_close_catalog_candidates'])
        ->assertJsonFragment(['confirm_catalog_candidate']);
});

test('assessment authorization and tenant lookup follow owned product roles', function () {
    [$owner, $organization] = assessmentWorkspace();
    [$viewer] = assessmentWorkspace(OrganizationRole::Viewer);
    OrganizationMembership::query()
        ->where('user_id', $viewer->getKey())
        ->update(['organization_id' => $organization->getKey()]);
    $viewer->update(['current_organization_id' => $organization->getKey()]);
    [$outsider] = assessmentWorkspace();
    $product = createAssessmentProduct($this, $owner);
    $snapshot = $product->snapshots()->firstOrFail();

    assessProduct($this, $viewer, $product, $snapshot->getKey())
        ->assertForbidden();
    assessProduct($this, $outsider, $product, $snapshot->getKey())
        ->assertNotFound();

    expect(OwnedProductAssessment::query()->count())->toBe(0);
});

test('only ready intake and a valid latest snapshot can be assessed', function () {
    [$owner] = assessmentWorkspace();
    $product = createAssessmentProduct($this, $owner, ['status' => 'draft']);
    $snapshot = $product->snapshots()->firstOrFail();

    assessProduct($this, $owner, $product, $snapshot->getKey())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('owned_product');
    assessProduct($this, $owner, $product, 'not-a-ulid')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('owned_product_snapshot_id');

    expect(OwnedProductAssessment::query()->count())->toBe(0);
});
