<?php

use App\Actions\Analyses\CreateBuyAnalysisDraft;
use App\Actions\Analyses\ReviewProductMatch;
use App\Actions\Analyses\RunBuyAnalysis;
use App\Actions\Analyses\SubmitAnalysis;
use App\Actions\Listings\CreateListing;
use App\Actions\Markets\SyncMarketReferenceData;
use App\Enums\Catalog\ProductMatchReviewDecision;
use App\Enums\Catalog\ProductMatchReviewStatus;
use App\Enums\Catalog\ProductMatchStatus;
use App\Enums\Listings\ListingImageKind;
use App\Exceptions\ProductMatchReviewConflictException;
use App\Filament\Resources\ProductMatchReviews\Pages\ListProductMatchReviews;
use App\Models\Analysis;
use App\Models\Brand;
use App\Models\ListingImage;
use App\Models\MarketplaceSource;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\PlatformAuditEvent;
use App\Models\ProductAlias;
use App\Models\ProductCategory;
use App\Models\ProductMatch;
use App\Models\ProductMatchReviewEvent;
use App\Models\ProductModel;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    app(SyncMarketReferenceData::class)->sync();
    $this->seed(PlanSeeder::class);
    Queue::fake();
});

function productMatchReviewAdmin(array $attributes = []): User
{
    $admin = User::factory()->create($attributes);
    $admin->forceFill(['is_super_admin' => true])->save();

    return $admin->fresh();
}

/** @return array{Analysis, ProductMatch, Organization, User} */
function pendingProductMatchReview(): array
{
    $owner = User::factory()->create();
    $organization = Organization::factory()->create([
        'name' => 'Product Match Review Workspace',
    ]);
    OrganizationMembership::factory()->owner()->create([
        'organization_id' => $organization,
        'user_id' => $owner,
    ]);
    $owner->forceFill([
        'current_organization_id' => $organization->getKey(),
    ])->save();
    $listing = app(CreateListing::class)->create(
        $organization,
        $owner,
        MarketplaceSource::query()->where('key', 'manual')->firstOrFail(),
        [
            'source_url' => 'https://review.example/listings/unknown-tool',
            'external_id' => (string) Str::uuid(),
            'marketplace_name' => 'Review Market',
            'title' => 'Unknown ZXQ-991 professional tool',
            'description' => 'Complete professional tool kit with charger and case.',
            'asking_price_minor' => 18999,
            'currency_code' => 'EUR',
            'seller_information' => 'Private seller.',
            'location' => 'Vienna',
            'source_country_code' => 'AT',
            'target_country_code' => 'DE',
            'status' => 'active',
            'notes' => null,
        ],
    );
    ListingImage::query()->create([
        'listing_id' => $listing->getKey(),
        'uploaded_by_user_id' => $owner->getKey(),
        'kind' => ListingImageKind::Product,
        'disk' => 'local',
        'path' => "testing/{$listing->getKey()}/product.png",
        'client_filename' => 'product.png',
        'mime_type' => 'image/png',
        'extension' => 'png',
        'size_bytes' => 2048,
        'width' => 800,
        'height' => 600,
        'checksum_sha256' => str_repeat('c', 64),
        'position' => 1,
    ]);
    $analysis = app(CreateBuyAnalysisDraft::class)->create(
        $organization,
        $owner,
        $listing->getKey(),
        'DE',
    );
    $analysis = app(SubmitAnalysis::class)->submit(
        $organization,
        $owner,
        $analysis->getKey(),
    );
    app(RunBuyAnalysis::class)->run(
        $analysis->getKey(),
        $analysis->currentDispatch()->valueOrFail('id'),
    );

    return [
        $analysis->fresh(),
        $analysis->currentProductMatch()->firstOrFail(),
        $organization,
        $owner,
    ];
}

/** @return array{ProductCategory, Brand, ProductModel} */
function productMatchReviewCatalog(string $suffix = 'primary'): array
{
    $category = ProductCategory::query()->create([
        'name' => "Review Tools {$suffix}",
        'slug' => "review-tools-{$suffix}",
    ]);
    $brand = Brand::query()->create(['name' => "Review Brand {$suffix}"]);
    $model = ProductModel::query()->create([
        'brand_id' => $brand->getKey(),
        'product_category_id' => $category->getKey(),
        'name' => "Reviewed Model {$suffix}",
        'model_number' => "RM-{$suffix}",
        'canonical_key' => "review-brand:rm-{$suffix}",
    ]);

    return [$category, $brand, $model];
}

test('a verified operator can confirm one pending match with immutable evidence and replay safety', function () {
    [$analysis, $pendingMatch, $organization] = pendingProductMatchReview();
    [, , $model] = productMatchReviewCatalog();
    $admin = productMatchReviewAdmin();
    $idempotencyKey = (string) Str::uuid();
    $reason = 'The model number is visible in the reviewed product photograph.';
    $action = app(ReviewProductMatch::class);

    expect(fn () => $action->review(
        $pendingMatch,
        User::factory()->create(),
        ProductMatchReviewDecision::Confirm,
        $pendingMatch->getKey(),
        (string) Str::uuid(),
        $reason,
        $model,
    ))->toThrow(AuthorizationException::class);

    $result = $action->review(
        productMatch: $pendingMatch,
        operator: $admin,
        decision: ProductMatchReviewDecision::Confirm,
        expectedCurrentMatchId: $pendingMatch->getKey(),
        idempotencyKey: $idempotencyKey,
        reason: $reason,
        productModel: $model,
        alias: 'ZXQ 991 professional tool',
        aliasLocale: 'en',
        ipAddress: '192.0.2.80',
        userAgent: 'Procura product match review test',
    );
    $confirmedMatch = $result['result_match'];
    $event = $result['event'];

    expect($result['created'])->toBeTrue()
        ->and($pendingMatch->fresh()->review_status)
        ->toBe(ProductMatchReviewStatus::Confirmed)
        ->and($confirmedMatch)->toBeInstanceOf(ProductMatch::class)
        ->and($confirmedMatch->status)->toBe(ProductMatchStatus::Matched)
        ->and($confirmedMatch->review_status)
        ->toBe(ProductMatchReviewStatus::Confirmed)
        ->and($confirmedMatch->run_number)->toBe(2)
        ->and($confirmedMatch->product_model_id)->toBe($model->getKey())
        ->and($confirmedMatch->method)->toBe('operator_review')
        ->and($event->product_match_id)->toBe($pendingMatch->getKey())
        ->and($event->result_product_match_id)->toBe($confirmedMatch->getKey())
        ->and($event->actor_user_id)->toBe($admin->getKey())
        ->and($event->organization_id)->toBe($organization->getKey())
        ->and($result['alias'])->toBeInstanceOf(ProductAlias::class)
        ->and($result['alias']->country_code)->toBe('DE')
        ->and($analysis->fresh()->currentProductMatch->is($confirmedMatch))->toBeTrue()
        ->and($analysis->fresh()->result_payload['needs_input'])
        ->not->toContain('model_uncertain')
        ->and($analysis->fresh()->result_payload['needs_input'])
        ->toContain('no_comparable_records')
        ->and(ProductMatch::query()->count())->toBe(2)
        ->and(ProductMatchReviewEvent::query()->count())->toBe(1)
        ->and(PlatformAuditEvent::query()
            ->where('action', 'product_match.reviewed')
            ->count())->toBe(1);

    $replay = $action->review(
        productMatch: $pendingMatch,
        operator: $admin,
        decision: ProductMatchReviewDecision::Confirm,
        expectedCurrentMatchId: $pendingMatch->getKey(),
        idempotencyKey: $idempotencyKey,
        reason: $reason,
        productModel: $model,
        alias: 'ZXQ 991 professional tool',
        aliasLocale: 'en',
    );

    expect($replay['created'])->toBeFalse()
        ->and($replay['event']->is($event))->toBeTrue()
        ->and(ProductMatch::query()->count())->toBe(2)
        ->and(ProductMatchReviewEvent::query()->count())->toBe(1);

    expect(fn () => $action->review(
        $pendingMatch,
        $admin,
        ProductMatchReviewDecision::Confirm,
        $pendingMatch->getKey(),
        $idempotencyKey,
        'Changed evidence cannot reuse an idempotency key.',
        $model,
    ))->toThrow(ProductMatchReviewConflictException::class);
    expect(fn () => $event->update(['reason' => 'Mutation is forbidden.']))
        ->toThrow(LogicException::class);
    expect(fn () => $pendingMatch->fresh()->update([
        'review_status' => ProductMatchReviewStatus::Rejected,
    ]))->toThrow(LogicException::class);
    expect(fn () => $confirmedMatch->delete())->toThrow(LogicException::class);
});

test('a rejection remains explicit and stale or invalid selections fail closed', function () {
    [$analysis, $pendingMatch] = pendingProductMatchReview();
    [, , $model] = productMatchReviewCatalog('rejection');
    [, , $otherModel] = productMatchReviewCatalog('other');
    $otherVariant = ProductVariant::query()->create([
        'product_model_id' => $otherModel->getKey(),
        'name' => 'Other model variant',
        'canonical_key' => 'review-brand:rm-other:variant',
        'sku' => 'RM-OTHER-V1',
    ]);
    $admin = productMatchReviewAdmin();
    $action = app(ReviewProductMatch::class);

    expect(fn () => $action->review(
        $pendingMatch,
        $admin,
        ProductMatchReviewDecision::Confirm,
        (string) Str::ulid(),
        (string) Str::uuid(),
        'This stale browser form must not overwrite the current match.',
        $model,
    ))->toThrow(ProductMatchReviewConflictException::class);

    expect(fn () => $action->review(
        $pendingMatch,
        $admin,
        ProductMatchReviewDecision::Confirm,
        $pendingMatch->getKey(),
        (string) Str::uuid(),
        'A variant from another product model must be rejected.',
        $model,
        $otherVariant,
    ))->toThrow(LogicException::class);

    $result = $action->review(
        productMatch: $pendingMatch,
        operator: $admin,
        decision: ProductMatchReviewDecision::Reject,
        expectedCurrentMatchId: $pendingMatch->getKey(),
        idempotencyKey: (string) Str::uuid(),
        reason: 'The listing contains no reliable model or serial evidence.',
    );

    expect($result['created'])->toBeTrue()
        ->and($result['result_match'])->toBeNull()
        ->and($result['alias'])->toBeNull()
        ->and($pendingMatch->fresh()->review_status)
        ->toBe(ProductMatchReviewStatus::Rejected)
        ->and($analysis->fresh()->result_payload['needs_input'])
        ->toContain('model_uncertain')
        ->and(ProductMatch::query()->count())->toBe(1)
        ->and(ProductAlias::query()->count())->toBe(0);
});

test('the Filament queue delegates confirmation through the review boundary', function () {
    [, $pendingMatch] = pendingProductMatchReview();
    [, , $model] = productMatchReviewCatalog('filament');
    $admin = productMatchReviewAdmin();

    $this->actingAs($admin);
    Livewire::test(ListProductMatchReviews::class)
        ->assertCanSeeTableRecords([$pendingMatch])
        ->assertTableActionVisible('confirmMatch', $pendingMatch)
        ->callTableAction('confirmMatch', $pendingMatch, [
            'expected_current_match_id' => $pendingMatch->getKey(),
            'idempotency_key' => (string) Str::uuid(),
            'product_model_id' => $model->getKey(),
            'product_variant_id' => null,
            'alias' => null,
            'alias_locale' => 'en',
            'reason' => 'The operator verified the exact model from source evidence.',
        ])
        ->assertHasNoTableActionErrors();

    expect(ProductMatchReviewEvent::query()->count())->toBe(1)
        ->and(ProductMatch::query()->count())->toBe(2)
        ->and($pendingMatch->fresh()->review_status)
        ->toBe(ProductMatchReviewStatus::Confirmed);
});

test('the Filament queue excludes a pending match after a newer analysis head exists', function () {
    [, $pendingMatch] = pendingProductMatchReview();
    [, , $model] = productMatchReviewCatalog('newer-head');
    $admin = productMatchReviewAdmin();

    ProductMatch::query()->create([
        'organization_id' => $pendingMatch->organization_id,
        'analysis_id' => $pendingMatch->analysis_id,
        'ai_analysis_id' => $pendingMatch->ai_analysis_id,
        'product_model_id' => $model->getKey(),
        'product_variant_id' => null,
        'run_number' => 2,
        'status' => ProductMatchStatus::Matched,
        'review_status' => ProductMatchReviewStatus::NotRequired,
        'method' => 'exact_catalog_alias',
        'matcher_version' => 'test-newer-head:v1',
        'input_hash' => hash('sha256', 'test-newer-head-input'),
        'match_key' => hash('sha256', 'test-newer-head-match'),
        'confidence_basis_points' => 9000,
        'candidate_snapshot' => [],
        'reason_codes' => ['exact_catalog_alias'],
    ]);

    $this->actingAs($admin);
    Livewire::test(ListProductMatchReviews::class)
        ->assertCanNotSeeTableRecords([$pendingMatch]);
});
