<?php

use App\Actions\Analyses\ConfirmAnalysisCosts;
use App\Actions\Analyses\CreateAnalysisComparable;
use App\Actions\Analyses\CreateBuyAnalysisDraft;
use App\Actions\Analyses\RunBuyAnalysis;
use App\Actions\Analyses\SubmitAnalysis;
use App\Actions\Listings\CreateListing;
use App\Actions\Markets\RecordExchangeRate;
use App\Actions\Markets\SyncMarketReferenceData;
use App\Enums\Listings\ListingImageKind;
use App\Enums\Organizations\OrganizationRole;
use App\Enums\Outcomes\ActualCostCategory;
use App\Models\ActualCostItem;
use App\Models\ActualCostSnapshot;
use App\Models\ActualPurchase;
use App\Models\ActualSale;
use App\Models\Analysis;
use App\Models\Brand;
use App\Models\EstimateAccuracyReport;
use App\Models\ListingImage;
use App\Models\MarketplaceSource;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\OutcomeEstimateAttribution;
use App\Models\OwnedProduct;
use App\Models\OwnedProductImage;
use App\Models\ProductAlias;
use App\Models\ProductCategory;
use App\Models\ProductModel;
use App\Models\RealizedProfit;
use App\Models\SalePortfolioEntry;
use App\Models\SalePortfolioEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function () {
    app(SyncMarketReferenceData::class)->sync();
    $this->seed(PlanSeeder::class);
});

function portfolioWorkspace(
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

function portfolioImage(
    OwnedProduct $product,
    User $owner,
    string $kind,
    int $position,
): OwnedProductImage {
    $identity = "portfolio-{$kind}-{$position}-{$product->getKey()}";

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
        'width' => 1600,
        'height' => 1200,
        'checksum_sha256' => hash('sha256', $identity),
        'position' => $position,
    ]);
}

function portfolioProduct(
    $test,
    User $owner,
    array $accessories = [],
): OwnedProduct {
    $ordinal = ProductCategory::query()->count() + 1;
    $brandName = "Portfolio Tools {$ordinal}";
    $modelName = "PT 18V-200 {$ordinal}";
    $category = ProductCategory::query()->create([
        'name' => "Portfolio tools {$ordinal}",
        'slug' => "portfolio-tools-{$ordinal}",
    ]);
    $brand = Brand::query()->create([
        'name' => $brandName,
    ]);
    $model = ProductModel::query()->create([
        'brand_id' => $brand->getKey(),
        'product_category_id' => $category->getKey(),
        'name' => $modelName,
        'model_number' => $modelName,
        'canonical_key' => "portfolio-tools:pt-18v-200-{$ordinal}",
    ]);
    ProductAlias::query()->create([
        'product_model_id' => $model->getKey(),
        'alias' => $modelName,
        'source' => 'sale_portfolio_test',
    ]);
    $productId = $test->actingAs($owner)
        ->postJson(route('api.v1.owned-products.store'), [
            'product_category_id' => $category->getKey(),
            'brand_name' => $brandName,
            'model_name' => $modelName,
            'condition' => 'used_good',
            'age_months' => 12,
            'accessories' => $accessories,
            'defects' => [],
            'purchase_history_known' => true,
            'purchase_history' => 'Private receipt retained.',
            'target_continent_code' => 'EU',
            'target_country_codes' => ['DE'],
            'cross_border_preference' => 'domestic_only',
            'desired_sale_speed' => 'balanced',
            'status' => 'ready',
            'notes' => null,
        ])
        ->assertCreated()
        ->json('data.id');
    $product = OwnedProduct::query()->findOrFail($productId);

    foreach (range(1, 3) as $position) {
        portfolioImage($product, $owner, 'product', $position);
    }
    portfolioImage($product, $owner, 'serial_label', 1);

    $test->actingAs($owner)
        ->postJson(
            route('api.v1.owned-products.assessments.store', $product),
            [
                'owned_product_snapshot_id' => (
                    $product->snapshots()->firstOrFail()->getKey()
                ),
            ],
        )
        ->assertCreated()
        ->assertJsonPath('data.status', 'ready');

    return $product->fresh();
}

function portfolioDraft(
    $test,
    User $owner,
    OwnedProduct $product,
): string {
    $assessmentId = $product->assessments()->firstOrFail()->getKey();
    $bandId = null;

    foreach ([20000, 22000, 24000] as $index => $price) {
        $position = $index + 1;
        $bandId = $test->actingAs($owner)
            ->postJson(
                route(
                    'api.v1.owned-products.comparables.store',
                    $product,
                ),
                [
                    'owned_product_assessment_id' => $assessmentId,
                    'marketplace_source_key' => 'manual',
                    'source_url' => (
                        "https://portfolio-market.example/items/{$position}"
                    ),
                    'external_id' => "portfolio-evidence-{$position}",
                    'marketplace_name' => 'Portfolio evidence',
                    'title' => "Portfolio Tools PT {$position}",
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
                ],
            )
            ->assertCreated()
            ->json('data.price_band.id');
    }

    return $test->actingAs($owner)
        ->postJson(
            route(
                'api.v1.owned-products.listing-drafts.store',
                $product,
            ),
            [
                'owned_product_assessment_id' => $assessmentId,
                'sell_price_band_id' => $bandId,
                'target_country_code' => 'DE',
                'target_currency_code' => 'EUR',
                'price_strategy' => 'recommended',
                'target_asking_price_minor' => 22000,
                'listing_language' => 'en',
                'price_override_reason' => null,
            ],
        )
        ->assertCreated()
        ->json('data.id');
}

function portfolioEntry(
    $test,
    User $owner,
    OwnedProduct $product,
    string $draftId,
    ?string $idempotencyKey = null,
) {
    return $test->actingAs($owner)->postJson(
        route('api.v1.owned-products.sale-portfolio.store', $product),
        [
            'sell_listing_draft_id' => $draftId,
            'idempotency_key' => $idempotencyKey ?? (string) Str::uuid(),
        ],
    );
}

function portfolioEventPayload(
    string $eventType,
    ?string $expectedEventId,
    array $overrides = [],
): array {
    return [
        'expected_current_event_id' => $expectedEventId,
        'event_type' => $eventType,
        'marketplace_name' => null,
        'marketplace_key' => null,
        'external_listing_id' => null,
        'external_listing_url' => null,
        'advertised_price_minor' => null,
        'advertised_currency_code' => null,
        'reason_code' => null,
        'note' => null,
        'occurred_at' => now()->startOfSecond()->toIso8601String(),
        'idempotency_key' => (string) Str::uuid(),
        ...$overrides,
    ];
}

function outcomePurchasePayload(array $overrides = []): array
{
    return [
        'expected_current_purchase_id' => null,
        'amount_minor' => 10000,
        'currency_code' => 'EUR',
        'reporting_currency_code' => 'EUR',
        'occurred_at' => now()->startOfSecond()->subDays(30)->toIso8601String(),
        'evidence_kind' => 'receipt',
        'evidence_reference' => 'receipt-1001',
        'correction_reason' => null,
        'note' => null,
        'idempotency_key' => (string) Str::uuid(),
        ...$overrides,
    ];
}

function outcomeCostPayload(
    ?string $expectedSnapshotId = null,
    bool $complete = true,
    array $overrides = [],
): array {
    $amounts = [
        'transport' => 1000,
        'repair' => 500,
        'platform_fees' => 700,
        'payment_fees' => 300,
        'customs' => 0,
        'tax' => 0,
        'marketing' => 200,
        'other_costs' => 300,
    ];
    $items = array_map(
        static function (ActualCostCategory $category) use (
            $amounts,
            $complete,
        ): array {
            $known = $complete || $category !== ActualCostCategory::Other;

            return $known ? [
                'category' => $category->value,
                'is_known' => true,
                'amount_minor' => $amounts[$category->value],
                'currency_code' => 'EUR',
                'occurred_at' => now()
                    ->startOfSecond()
                    ->subDays(5)
                    ->toIso8601String(),
                'evidence_kind' => 'invoice',
                'evidence_reference' => "cost-{$category->value}",
                'note' => null,
            ] : [
                'category' => $category->value,
                'is_known' => false,
                'amount_minor' => null,
                'currency_code' => null,
                'occurred_at' => null,
                'evidence_kind' => null,
                'evidence_reference' => null,
                'note' => null,
            ];
        },
        ActualCostCategory::cases(),
    );

    return [
        'expected_current_cost_snapshot_id' => $expectedSnapshotId,
        'reporting_currency_code' => 'EUR',
        'items' => $items,
        'correction_reason' => $expectedSnapshotId === null
            ? null
            : 'completed_cost_evidence',
        'note' => null,
        'idempotency_key' => (string) Str::uuid(),
        ...$overrides,
    ];
}

function outcomeSalePayload(
    string $portfolioEventId,
    string $outcomeType = 'sold',
    array $overrides = [],
): array {
    $sold = $outcomeType === 'sold';

    return [
        'expected_current_sale_id' => null,
        'sale_portfolio_event_id' => $portfolioEventId,
        'outcome_type' => $outcomeType,
        'amount_minor' => $sold ? 20000 : null,
        'currency_code' => $sold ? 'EUR' : null,
        'reporting_currency_code' => $sold ? 'EUR' : null,
        'occurred_at' => now()->startOfSecond()->toIso8601String(),
        'evidence_kind' => $sold
            ? 'bank_statement'
            : 'manual_confirmation',
        'evidence_reference' => $sold ? 'bank-sale-1001' : null,
        'reason_code' => $sold ? null : 'listing_closed_without_sale',
        'correction_reason' => null,
        'note' => null,
        'idempotency_key' => (string) Str::uuid(),
        ...$overrides,
    ];
}

function accuracyReadyAnalysis(
    User $owner,
    Organization $organization,
): Analysis {
    config([
        'comparable_selection.max_candidates' => 100,
        'comparable_selection.max_selected' => 20,
        'comparable_selection.minimum_selected' => 3,
    ]);
    $ordinal = ProductCategory::query()->count() + 1;
    $category = ProductCategory::query()->create([
        'name' => "Accuracy tools {$ordinal}",
        'slug' => "accuracy-tools-{$ordinal}",
    ]);
    $brand = Brand::query()->create([
        'name' => "Accuracy Tools {$ordinal}",
    ]);
    $modelName = "AT 18V-{$ordinal}";
    $model = ProductModel::query()->create([
        'brand_id' => $brand->getKey(),
        'product_category_id' => $category->getKey(),
        'name' => $modelName,
        'model_number' => $modelName,
        'canonical_key' => "accuracy-tools:{$ordinal}",
    ]);
    ProductAlias::query()->create([
        'product_model_id' => $model->getKey(),
        'alias' => $modelName,
        'source' => 'estimate_accuracy_test',
    ]);
    $listing = app(CreateListing::class)->create(
        $organization,
        $owner,
        MarketplaceSource::query()->where('key', 'manual')->firstOrFail(),
        [
            'source_url' => (
                "https://accuracy-source.example/listings/{$ordinal}"
            ),
            'external_id' => "accuracy-target-{$ordinal}",
            'marketplace_name' => 'Accuracy Source Market',
            'title' => "{$brand->name} {$modelName}",
            'description' => 'Used tool with exact retained estimate evidence.',
            'asking_price_minor' => 10000,
            'currency_code' => 'EUR',
            'seller_information' => 'Verified private seller.',
            'location' => 'Berlin',
            'source_country_code' => 'DE',
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
        'path' => "testing/{$listing->getKey()}/accuracy-product.png",
        'client_filename' => 'accuracy-product.png',
        'mime_type' => 'image/png',
        'extension' => 'png',
        'size_bytes' => 2048,
        'width' => 800,
        'height' => 600,
        'checksum_sha256' => hash('sha256', $listing->getKey()),
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
    $source = MarketplaceSource::query()
        ->where('key', 'manual')
        ->firstOrFail();

    foreach ([18000, 20000, 22000] as $index => $amount) {
        app(CreateAnalysisComparable::class)->create(
            $organization,
            $owner,
            $analysis->fresh(),
            $source,
            [
                'product_variant_id' => null,
                'source_url' => (
                    "https://accuracy-market.example/{$ordinal}/{$index}"
                ),
                'external_id' => "accuracy-{$ordinal}-{$index}",
                'marketplace_name' => 'Accuracy Comparable Market',
                'title' => "{$modelName} comparable {$index}",
                'description' => 'Preserved manual comparable.',
                'listing_type' => 'product',
                'condition_code' => 'used_good',
                'seller_type' => 'private',
                'asking_price_minor' => $amount,
                'currency_code' => 'EUR',
                'country_code' => 'DE',
                'location' => 'Berlin',
                'included_accessories' => [],
                'missing_accessories' => [],
                'published_at' => null,
                'observed_at' => now()
                    ->startOfSecond()
                    ->subDays(4 - $index)
                    ->toIso8601String(),
            ],
        );
    }

    $analysis->refresh();

    return app(ConfirmAnalysisCosts::class)->confirm(
        $organization,
        $owner,
        $analysis,
        [
            'price_estimate_id' => $analysis
                ->currentPriceEstimate()
                ->valueOrFail('id'),
            'risk_assessment_id' => $analysis
                ->currentRiskAssessment()
                ->valueOrFail('id'),
            'currency_code' => 'EUR',
            'purchase_price_minor' => 10000,
            'transport_minor' => 1000,
            'repair_minor' => 500,
            'platform_fees_minor' => 700,
            'payment_fees_minor' => 300,
            'customs_minor' => 0,
            'tax_minor' => 0,
            'other_costs_minor' => 500,
            'safety_reserve_minor' => 0,
            'regional_compatibility_confirmed' => true,
        ],
    )['analysis']->fresh();
}

function accuracyCostPayload(): array
{
    $payload = outcomeCostPayload();
    $payload['items'] = array_map(
        static function (array $item): array {
            if ($item['category'] === ActualCostCategory::Marketing->value) {
                $item['amount_minor'] = 0;
            }

            if ($item['category'] === ActualCostCategory::Other->value) {
                $item['amount_minor'] = 500;
            }

            return $item;
        },
        $payload['items'],
    );

    return $payload;
}

function accuracyCompleteOutcome(
    $test,
    User $owner,
    OwnedProduct $product,
): RealizedProfit {
    $entryId = portfolioEntry(
        $test,
        $owner,
        $product,
        portfolioDraft($test, $owner, $product),
    )
        ->assertCreated()
        ->json('meta.entry_id');
    $publishedId = $test->actingAs($owner)
        ->postJson(
            route(
                'api.v1.owned-products.sale-portfolio.events.store',
                [$product, $entryId],
            ),
            portfolioEventPayload('published', null, [
                'marketplace_name' => 'Accuracy Outcome Market',
                'marketplace_key' => 'accuracy-outcome-market',
                'external_listing_id' => "accuracy-{$product->getKey()}",
                'external_listing_url' => (
                    "https://accuracy-outcome.example/{$product->getKey()}"
                ),
                'advertised_price_minor' => 20000,
                'advertised_currency_code' => 'EUR',
                'occurred_at' => now()
                    ->startOfSecond()
                    ->subDays(3)
                    ->toIso8601String(),
            ]),
        )
        ->assertCreated()
        ->json('meta.event.id');
    $test->actingAs($owner)
        ->postJson(
            route(
                'api.v1.owned-products.outcomes.purchases.store',
                $product,
            ),
            outcomePurchasePayload(),
        )
        ->assertCreated();
    $test->actingAs($owner)
        ->postJson(
            route(
                'api.v1.owned-products.outcomes.cost-snapshots.store',
                $product,
            ),
            accuracyCostPayload(),
        )
        ->assertCreated();
    $realizedId = $test->actingAs($owner)
        ->postJson(
            route(
                'api.v1.owned-products.outcomes.sales.store',
                [$product, $entryId],
            ),
            outcomeSalePayload($publishedId),
        )
        ->assertCreated()
        ->assertJsonPath('data.complete', true)
        ->json('data.current_realized_profit_id');

    return RealizedProfit::query()->findOrFail($realizedId);
}

test('a review complete draft enters an immutable source bound portfolio', function () {
    [$owner] = portfolioWorkspace();
    $product = portfolioProduct($this, $owner);
    $draftId = portfolioDraft($this, $owner, $product);
    $idempotencyKey = (string) Str::uuid();
    $response = portfolioEntry(
        $this,
        $owner,
        $product,
        $draftId,
        $idempotencyKey,
    );
    $entryId = $response
        ->assertCreated()
        ->assertJsonPath('meta.created', true)
        ->assertJsonPath('data.entries.0.current_status', 'draft')
        ->assertJsonPath('data.entries.0.source_evidence_current', true)
        ->assertJsonPath('data.entries.0.initial_asking_price_minor', 22000)
        ->assertJsonCount(0, 'data.available_listing_drafts')
        ->json('meta.entry_id');

    portfolioEntry(
        $this,
        $owner,
        $product,
        $draftId,
        $idempotencyKey,
    )
        ->assertOk()
        ->assertJsonPath('meta.created', false)
        ->assertJsonPath('meta.entry_id', $entryId);

    expect(SalePortfolioEntry::query()->count())->toBe(1)
        ->and(Schema::hasColumn(
            'sale_portfolio_entries',
            'actual_sale_price_minor',
        ))->toBeFalse()
        ->and(fn () => SalePortfolioEntry::query()
            ->firstOrFail()
            ->update(['initial_asking_price_minor' => 1]))
        ->toThrow(LogicException::class)
        ->and(fn () => SalePortfolioEntry::query()
            ->firstOrFail()
            ->delete())
        ->toThrow(LogicException::class);
});

test('manual publication events preserve price and lifecycle history', function () {
    [$owner] = portfolioWorkspace();
    $product = portfolioProduct($this, $owner);
    $draftId = portfolioDraft($this, $owner, $product);
    $entryId = portfolioEntry($this, $owner, $product, $draftId)
        ->assertCreated()
        ->json('meta.entry_id');
    $publicationKey = (string) Str::uuid();
    $publishedPayload = portfolioEventPayload('published', null, [
        'marketplace_name' => 'Example Market',
        'marketplace_key' => 'example-market',
        'external_listing_id' => 'EXT-1001',
        'external_listing_url' => (
            'https://example-market.test/listings/EXT-1001'
        ),
        'advertised_price_minor' => 22000,
        'advertised_currency_code' => 'EUR',
        'idempotency_key' => $publicationKey,
    ]);
    $this->actingAs($owner)
        ->postJson(
            route(
                'api.v1.owned-products.sale-portfolio.events.store',
                [$product, $entryId],
            ),
            [
                ...$publishedPayload,
                'external_listing_url' => (
                    'http://example-market.test/listings/EXT-1001'
                ),
                'idempotency_key' => (string) Str::uuid(),
            ],
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('external_listing_url');
    $published = $this->actingAs($owner)
        ->postJson(
            route(
                'api.v1.owned-products.sale-portfolio.events.store',
                [$product, $entryId],
            ),
            $publishedPayload,
        );
    $publishedEventId = $published
        ->assertCreated()
        ->assertJsonPath('data.entries.0.current_status', 'listed')
        ->assertJsonPath(
            'data.entries.0.current_event.advertised_price_minor',
            22000,
        )
        ->assertJsonPath('meta.event.sequence', 1)
        ->json('meta.event.id');

    $this->actingAs($owner)
        ->postJson(
            route(
                'api.v1.owned-products.sale-portfolio.events.store',
                [$product, $entryId],
            ),
            $publishedPayload,
        )
        ->assertOk()
        ->assertJsonPath('meta.created', false);
    $this->actingAs($owner)
        ->postJson(
            route(
                'api.v1.owned-products.sale-portfolio.events.store',
                [$product, $entryId],
            ),
            [
                ...$publishedPayload,
                'advertised_price_minor' => 21000,
            ],
        )
        ->assertConflict()
        ->assertJsonPath('code', 'sale_portfolio_idempotency_conflict');

    $changed = $this->actingAs($owner)
        ->postJson(
            route(
                'api.v1.owned-products.sale-portfolio.events.store',
                [$product, $entryId],
            ),
            portfolioEventPayload('price_changed', $publishedEventId, [
                'advertised_price_minor' => 21000,
                'advertised_currency_code' => 'EUR',
            ]),
        );
    $changedEventId = $changed
        ->assertCreated()
        ->assertJsonPath('meta.event.sequence', 2)
        ->assertJsonPath(
            'meta.event.external_listing_id',
            'EXT-1001',
        )
        ->assertJsonPath('meta.event.advertised_price_minor', 21000)
        ->assertJsonCount(2, 'data.entries.0.events')
        ->json('meta.event.id');

    $this->actingAs($owner)
        ->postJson(
            route(
                'api.v1.owned-products.sale-portfolio.events.store',
                [$product, $entryId],
            ),
            portfolioEventPayload('withdrawn', $publishedEventId, [
                'reason_code' => 'seller_changed_plan',
            ]),
        )
        ->assertConflict()
        ->assertJsonPath('code', 'sale_portfolio_stale_state');

    $reservedEventId = $this->actingAs($owner)
        ->postJson(
            route(
                'api.v1.owned-products.sale-portfolio.events.store',
                [$product, $entryId],
            ),
            portfolioEventPayload('reserved', $changedEventId),
        )
        ->assertCreated()
        ->assertJsonPath('data.entries.0.current_status', 'reserved')
        ->json('meta.event.id');
    $this->actingAs($owner)
        ->postJson(
            route(
                'api.v1.owned-products.sale-portfolio.events.store',
                [$product, $entryId],
            ),
            portfolioEventPayload('price_changed', $reservedEventId, [
                'advertised_price_minor' => 20500,
                'advertised_currency_code' => 'EUR',
            ]),
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('event_type');
    $this->actingAs($owner)
        ->postJson(
            route(
                'api.v1.owned-products.sale-portfolio.events.store',
                [$product, $entryId],
            ),
            portfolioEventPayload('withdrawn', $reservedEventId),
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reason_code');
    $withdrawnEventId = $this->actingAs($owner)
        ->postJson(
            route(
                'api.v1.owned-products.sale-portfolio.events.store',
                [$product, $entryId],
            ),
            portfolioEventPayload('withdrawn', $reservedEventId, [
                'reason_code' => 'seller_changed_plan',
            ]),
        )
        ->assertCreated()
        ->assertJsonPath('data.entries.0.current_status', 'withdrawn')
        ->assertJsonPath(
            'data.entries.0.current_event.advertised_price_minor',
            21000,
        )
        ->json('meta.event.id');
    $relistedEventId = $this->actingAs($owner)
        ->postJson(
            route(
                'api.v1.owned-products.sale-portfolio.events.store',
                [$product, $entryId],
            ),
            portfolioEventPayload('relisted', $withdrawnEventId, [
                'marketplace_name' => 'Example Market',
                'marketplace_key' => 'example-market',
                'external_listing_id' => 'EXT-1001',
                'external_listing_url' => (
                    'https://example-market.test/listings/EXT-1001'
                ),
                'advertised_price_minor' => 20500,
                'advertised_currency_code' => 'EUR',
            ]),
        )
        ->assertCreated()
        ->assertJsonPath('data.entries.0.current_status', 'listed')
        ->assertJsonPath('meta.event.sequence', 5)
        ->json('meta.event.id');
    $this->actingAs($owner)
        ->postJson(
            route(
                'api.v1.owned-products.sale-portfolio.events.store',
                [$product, $entryId],
            ),
            portfolioEventPayload('expired', $relistedEventId),
        )
        ->assertCreated()
        ->assertJsonPath('data.entries.0.current_status', 'expired')
        ->assertJsonPath('meta.event.sequence', 6);

    expect(SalePortfolioEvent::query()->count())->toBe(6)
        ->and(SalePortfolioEvent::query()
            ->orderBy('sequence')
            ->pluck('advertised_price_minor')
            ->all())
        ->toBe([22000, 21000, 21000, 21000, 20500, 20500])
        ->and(fn () => SalePortfolioEvent::query()
            ->firstOrFail()
            ->update(['note' => 'rewritten']))
        ->toThrow(LogicException::class)
        ->and(fn () => SalePortfolioEvent::query()
            ->firstOrFail()
            ->delete())
        ->toThrow(LogicException::class);
});

test('stale and review incomplete listing evidence cannot be published', function () {
    [$owner] = portfolioWorkspace();
    $product = portfolioProduct($this, $owner);
    $draftId = portfolioDraft($this, $owner, $product);

    config([
        'sell_listing_content.generator_version' => (
            'deterministic-sell-listing-generator:v-next'
        ),
    ]);
    portfolioEntry($this, $owner, $product, $draftId)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('sell_listing_draft_id');
    config([
        'sell_listing_content.generator_version' => (
            'deterministic-sell-listing-generator:v1'
        ),
    ]);
    $entryId = portfolioEntry($this, $owner, $product, $draftId)
        ->assertCreated()
        ->json('meta.entry_id');
    config([
        'sell_listing_content.photo_readiness.minimum_product_images' => 4,
    ]);
    $this->actingAs($owner)
        ->getJson(
            route('api.v1.owned-products.sale-portfolio.show', $product),
        )
        ->assertOk()
        ->assertJsonPath(
            'data.entries.0.source_evidence_current',
            false,
        );
    $this->actingAs($owner)
        ->postJson(
            route(
                'api.v1.owned-products.sale-portfolio.events.store',
                [$product, $entryId],
            ),
            portfolioEventPayload('published', null, [
                'marketplace_name' => 'Example Market',
                'marketplace_key' => 'example-market',
                'external_listing_id' => 'EXT-STALE',
                'external_listing_url' => (
                    'https://example-market.test/listings/EXT-STALE'
                ),
                'advertised_price_minor' => 22000,
                'advertised_currency_code' => 'EUR',
            ]),
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('sale_portfolio_entry_id');

    config([
        'sell_listing_content.photo_readiness.minimum_product_images' => 3,
    ]);
    $reviewProduct = portfolioProduct(
        $this,
        $owner,
        ['Battery and charger'],
    );
    $reviewDraftId = portfolioDraft($this, $owner, $reviewProduct);
    portfolioEntry($this, $owner, $reviewProduct, $reviewDraftId)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('sell_listing_draft_id');
});

test('sale portfolio access follows tenant and owned product roles', function () {
    [$owner, $organization] = portfolioWorkspace();
    [$viewer] = portfolioWorkspace(OrganizationRole::Viewer);
    OrganizationMembership::query()
        ->where('user_id', $viewer->getKey())
        ->update(['organization_id' => $organization->getKey()]);
    $viewer->update([
        'current_organization_id' => $organization->getKey(),
    ]);
    [$outsider] = portfolioWorkspace();
    $product = portfolioProduct($this, $owner);
    $draftId = portfolioDraft($this, $owner, $product);

    $this->actingAs($viewer)
        ->getJson(
            route('api.v1.owned-products.sale-portfolio.show', $product),
        )
        ->assertOk();
    portfolioEntry($this, $viewer, $product, $draftId)
        ->assertForbidden();
    $this->actingAs($outsider)
        ->getJson(
            route('api.v1.owned-products.sale-portfolio.show', $product),
        )
        ->assertNotFound();
    portfolioEntry($this, $outsider, $product, $draftId)
        ->assertNotFound();

    expect(SalePortfolioEntry::query()->count())->toBe(0);
});

test('complete immutable outcome evidence creates exact realized profit', function () {
    [$owner] = portfolioWorkspace();
    $product = portfolioProduct($this, $owner);
    $draftId = portfolioDraft($this, $owner, $product);
    $entryId = portfolioEntry($this, $owner, $product, $draftId)
        ->assertCreated()
        ->json('meta.entry_id');
    $publishedEventId = $this->actingAs($owner)
        ->postJson(
            route(
                'api.v1.owned-products.sale-portfolio.events.store',
                [$product, $entryId],
            ),
            portfolioEventPayload('published', null, [
                'marketplace_name' => 'Outcome Market',
                'marketplace_key' => 'outcome-market',
                'external_listing_id' => 'OUTCOME-1001',
                'external_listing_url' => (
                    'https://outcome-market.test/items/OUTCOME-1001'
                ),
                'advertised_price_minor' => 22000,
                'advertised_currency_code' => 'EUR',
                'occurred_at' => now()
                    ->startOfSecond()
                    ->subDays(3)
                    ->toIso8601String(),
            ]),
        )
        ->assertCreated()
        ->json('meta.event.id');
    $purchaseKey = (string) Str::uuid();
    $purchasePayload = outcomePurchasePayload([
        'idempotency_key' => $purchaseKey,
    ]);
    $purchaseId = $this->actingAs($owner)
        ->postJson(
            route(
                'api.v1.owned-products.outcomes.purchases.store',
                $product,
            ),
            $purchasePayload,
        )
        ->assertCreated()
        ->assertJsonPath('meta.created', true)
        ->assertJsonPath(
            'meta.purchase.source_amount_minor',
            10000,
        )
        ->assertJsonPath(
            'meta.purchase.conversion.direction',
            'identity',
        )
        ->assertJsonPath('data.complete', false)
        ->json('meta.purchase.id');
    $this->actingAs($owner)
        ->postJson(
            route(
                'api.v1.owned-products.outcomes.purchases.store',
                $product,
            ),
            $purchasePayload,
        )
        ->assertOk()
        ->assertJsonPath('meta.created', false);
    $this->actingAs($owner)
        ->postJson(
            route(
                'api.v1.owned-products.outcomes.purchases.store',
                $product,
            ),
            [
                ...$purchasePayload,
                'amount_minor' => 9000,
            ],
        )
        ->assertConflict()
        ->assertJsonPath('code', 'outcome_idempotency_conflict');

    $incompleteCostId = $this->actingAs($owner)
        ->postJson(
            route(
                'api.v1.owned-products.outcomes.cost-snapshots.store',
                $product,
            ),
            outcomeCostPayload(complete: false),
        )
        ->assertCreated()
        ->assertJsonPath('meta.cost_snapshot.known_count', 7)
        ->assertJsonPath('meta.cost_snapshot.unknown_count', 1)
        ->assertJsonPath('data.complete', false)
        ->json('meta.cost_snapshot.id');
    $saleId = $this->actingAs($owner)
        ->postJson(
            route(
                'api.v1.owned-products.outcomes.sales.store',
                [$product, $entryId],
            ),
            outcomeSalePayload($publishedEventId),
        )
        ->assertCreated()
        ->assertJsonPath('meta.sale.outcome_type', 'sold')
        ->assertJsonPath('meta.sale.source_amount_minor', 20000)
        ->assertJsonPath('data.complete', false)
        ->json('meta.sale.id');
    $completeCostId = $this->actingAs($owner)
        ->postJson(
            route(
                'api.v1.owned-products.outcomes.cost-snapshots.store',
                $product,
            ),
            outcomeCostPayload($incompleteCostId, complete: true),
        )
        ->assertCreated()
        ->assertJsonPath('data.complete', true)
        ->assertJsonPath(
            'data.realized_profits.0.purchase_price_minor',
            10000,
        )
        ->assertJsonPath(
            'data.realized_profits.0.actual_costs_minor',
            3000,
        )
        ->assertJsonPath(
            'data.realized_profits.0.sale_price_minor',
            20000,
        )
        ->assertJsonPath(
            'data.realized_profits.0.net_profit_minor',
            7000,
        )
        ->json('meta.cost_snapshot.id');

    expect(ActualPurchase::query()->count())->toBe(1)
        ->and(ActualCostSnapshot::query()->count())->toBe(2)
        ->and(ActualCostItem::query()->count())->toBe(16)
        ->and(ActualSale::query()->count())->toBe(1)
        ->and(RealizedProfit::query()->count())->toBe(1)
        ->and(RealizedProfit::query()->firstOrFail()->actual_purchase_id)
        ->toBe($purchaseId)
        ->and(RealizedProfit::query()->firstOrFail()->actual_cost_snapshot_id)
        ->toBe($completeCostId)
        ->and(RealizedProfit::query()->firstOrFail()->actual_sale_id)
        ->toBe($saleId)
        ->and(fn () => ActualPurchase::query()
            ->firstOrFail()
            ->update(['source_amount_minor' => 1]))
        ->toThrow(LogicException::class)
        ->and(fn () => ActualCostSnapshot::query()
            ->firstOrFail()
            ->delete())
        ->toThrow(LogicException::class)
        ->and(fn () => ActualSale::query()
            ->firstOrFail()
            ->update(['source_amount_minor' => 1]))
        ->toThrow(LogicException::class)
        ->and(fn () => RealizedProfit::query()
            ->firstOrFail()
            ->delete())
        ->toThrow(LogicException::class);

    $this->actingAs($owner)
        ->postJson(
            route(
                'api.v1.owned-products.sale-portfolio.events.store',
                [$product, $entryId],
            ),
            portfolioEventPayload('price_changed', $publishedEventId, [
                'advertised_price_minor' => 21000,
                'advertised_currency_code' => 'EUR',
            ]),
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('sale_portfolio_entry_id');
});

test('cancelled and no-sale outcomes stay explicit and never create money', function () {
    [$owner] = portfolioWorkspace();
    $product = portfolioProduct($this, $owner);
    $entryId = portfolioEntry(
        $this,
        $owner,
        $product,
        portfolioDraft($this, $owner, $product),
    )
        ->assertCreated()
        ->json('meta.entry_id');
    $publishedEventId = $this->actingAs($owner)
        ->postJson(
            route(
                'api.v1.owned-products.sale-portfolio.events.store',
                [$product, $entryId],
            ),
            portfolioEventPayload('published', null, [
                'marketplace_name' => 'Outcome Market',
                'marketplace_key' => 'outcome-market',
                'external_listing_id' => 'NO-SALE-1001',
                'external_listing_url' => (
                    'https://outcome-market.test/items/NO-SALE-1001'
                ),
                'advertised_price_minor' => 22000,
                'advertised_currency_code' => 'EUR',
                'occurred_at' => now()
                    ->startOfSecond()
                    ->subDays(2)
                    ->toIso8601String(),
            ]),
        )
        ->assertCreated()
        ->json('meta.event.id');

    $this->actingAs($owner)
        ->postJson(
            route(
                'api.v1.owned-products.outcomes.sales.store',
                [$product, $entryId],
            ),
            outcomeSalePayload($publishedEventId, 'no_sale'),
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('outcome_type');
    $withdrawnId = $this->actingAs($owner)
        ->postJson(
            route(
                'api.v1.owned-products.sale-portfolio.events.store',
                [$product, $entryId],
            ),
            portfolioEventPayload('withdrawn', $publishedEventId, [
                'reason_code' => 'seller_changed_plan',
                'occurred_at' => now()
                    ->startOfSecond()
                    ->subDay()
                    ->toIso8601String(),
            ]),
        )
        ->assertCreated()
        ->json('meta.event.id');
    $this->actingAs($owner)
        ->postJson(
            route(
                'api.v1.owned-products.outcomes.sales.store',
                [$product, $entryId],
            ),
            outcomeSalePayload($withdrawnId, 'no_sale'),
        )
        ->assertCreated()
        ->assertJsonPath('meta.sale.outcome_type', 'no_sale')
        ->assertJsonPath('meta.sale.source_amount_minor', null)
        ->assertJsonPath('data.current_sold_sale_id', null)
        ->assertJsonPath('data.complete', false);

    expect(RealizedProfit::query()->count())->toBe(0);
});

test('outcome corrections require the current immutable head', function () {
    [$owner] = portfolioWorkspace();
    $product = portfolioProduct($this, $owner);
    $purchaseId = $this->actingAs($owner)
        ->postJson(
            route(
                'api.v1.owned-products.outcomes.purchases.store',
                $product,
            ),
            outcomePurchasePayload(),
        )
        ->assertCreated()
        ->json('meta.purchase.id');

    $this->actingAs($owner)
        ->postJson(
            route(
                'api.v1.owned-products.outcomes.purchases.store',
                $product,
            ),
            outcomePurchasePayload([
                'expected_current_purchase_id' => null,
                'amount_minor' => 11000,
                'correction_reason' => 'receipt_corrected',
            ]),
        )
        ->assertConflict()
        ->assertJsonPath('code', 'actual_purchase_stale_state');
    $this->actingAs($owner)
        ->postJson(
            route(
                'api.v1.owned-products.outcomes.purchases.store',
                $product,
            ),
            outcomePurchasePayload([
                'expected_current_purchase_id' => $purchaseId,
                'amount_minor' => 11000,
                'correction_reason' => null,
            ]),
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('correction_reason');
    $this->actingAs($owner)
        ->postJson(
            route(
                'api.v1.owned-products.outcomes.purchases.store',
                $product,
            ),
            outcomePurchasePayload([
                'expected_current_purchase_id' => $purchaseId,
                'amount_minor' => 11000,
                'correction_reason' => 'receipt_corrected',
            ]),
        )
        ->assertCreated()
        ->assertJsonPath('meta.purchase.sequence', 2)
        ->assertJsonPath('meta.purchase.previous_purchase_id', $purchaseId);

    expect(ActualPurchase::query()->count())->toBe(2);
});

test('realized currency conversion requires one exact dated rate record', function () {
    [$owner] = portfolioWorkspace();
    $product = portfolioProduct($this, $owner);
    $occurredAt = CarbonImmutable::now()->startOfSecond()->subDays(30);
    $payload = outcomePurchasePayload([
        'amount_minor' => 10000,
        'currency_code' => 'USD',
        'reporting_currency_code' => 'EUR',
        'occurred_at' => $occurredAt->toIso8601String(),
    ]);

    $this->actingAs($owner)
        ->postJson(
            route(
                'api.v1.owned-products.outcomes.purchases.store',
                $product,
            ),
            $payload,
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reporting_currency_code');

    $rate = app(RecordExchangeRate::class)->record(
        baseCurrencyCode: 'USD',
        quoteCurrencyCode: 'EUR',
        rateValue: '0.500000000000000000',
        provider: 'outcome-test',
        providerReference: 'usd-eur-historical-1',
        evidenceHash: hash('sha256', 'usd-eur-historical-1'),
        effectiveAt: $occurredAt->subDay(),
        publishedAt: $occurredAt->subDay(),
        fetchedAt: CarbonImmutable::now()->startOfSecond()->subMinute(),
        rawEvidence: ['source' => 'outcome-test'],
    )['rate'];
    $response = $this->actingAs($owner)
        ->postJson(
            route(
                'api.v1.owned-products.outcomes.purchases.store',
                $product,
            ),
            [
                ...$payload,
                'idempotency_key' => (string) Str::uuid(),
            ],
        );

    $response
        ->assertCreated()
        ->assertJsonPath('meta.purchase.source_amount_minor', 10000)
        ->assertJsonPath('meta.purchase.source_currency_code', 'USD')
        ->assertJsonPath('meta.purchase.reporting_amount_minor', 5000)
        ->assertJsonPath('meta.purchase.reporting_currency_code', 'EUR')
        ->assertJsonPath(
            'meta.purchase.conversion.exchange_rate_id',
            $rate->getKey(),
        )
        ->assertJsonPath('meta.purchase.conversion.direction', 'direct')
        ->assertJsonPath(
            'meta.purchase.conversion.provider_reference',
            'usd-eur-historical-1',
        );
});

test('outcome access remains tenant and role isolated', function () {
    [$owner, $organization] = portfolioWorkspace();
    [$viewer] = portfolioWorkspace(OrganizationRole::Viewer);
    OrganizationMembership::query()
        ->where('user_id', $viewer->getKey())
        ->update(['organization_id' => $organization->getKey()]);
    $viewer->update([
        'current_organization_id' => $organization->getKey(),
    ]);
    [$outsider] = portfolioWorkspace();
    $product = portfolioProduct($this, $owner);

    $this->actingAs($viewer)
        ->getJson(
            route('api.v1.owned-products.outcomes.show', $product),
        )
        ->assertOk()
        ->assertJsonPath('data.complete', false);
    $this->actingAs($viewer)
        ->postJson(
            route(
                'api.v1.owned-products.outcomes.purchases.store',
                $product,
            ),
            outcomePurchasePayload(),
        )
        ->assertForbidden();
    $this->actingAs($outsider)
        ->getJson(
            route('api.v1.owned-products.outcomes.show', $product),
        )
        ->assertNotFound();

    expect(ActualPurchase::query()->count())->toBe(0);
});

test('estimate accuracy uses exact immutable attribution and bounded metric errors', function () {
    [$owner, $organization] = portfolioWorkspace();
    $product = portfolioProduct($this, $owner);
    $realized = accuracyCompleteOutcome($this, $owner, $product);
    $analysis = accuracyReadyAnalysis($owner, $organization);
    $estimate = $analysis->currentProfitEstimate()->firstOrFail();

    $this->actingAs($owner)
        ->getJson(route(
            'api.v1.owned-products.outcomes.estimate-candidates.index',
            $product,
        ))
        ->assertOk()
        ->assertJsonPath('meta.count', 1)
        ->assertJsonPath('data.0.analysis_id', $analysis->getKey())
        ->assertJsonPath('data.0.profit_estimate_id', $estimate->getKey());

    $idempotencyKey = (string) Str::uuid();
    $payload = [
        'expected_current_attribution_id' => null,
        'expected_current_accuracy_report_id' => null,
        'realized_profit_id' => $realized->getKey(),
        'analysis_id' => $analysis->getKey(),
        'profit_estimate_id' => $estimate->getKey(),
        'reason_code' => 'original_buy_estimate_confirmed',
        'evidence_kind' => 'manual_confirmation',
        'evidence_reference' => 'retained-deal-notes-1001',
        'correction_reason' => null,
        'note' => 'The buyer confirmed this exact analysis.',
        'idempotency_key' => $idempotencyKey,
    ];
    $expectedSaleError = 20000 - $estimate->expected_sale_price_minor;
    $expectedProfitError = 7000 - $estimate->expected_net_profit_minor;
    $response = $this->actingAs($owner)
        ->postJson(
            route(
                'api.v1.owned-products.outcomes.estimate-attributions.store',
                $product,
            ),
            $payload,
        )
        ->assertCreated()
        ->assertJsonPath('meta.created', true)
        ->assertJsonPath('meta.attribution.sequence', 1)
        ->assertJsonPath('meta.attribution.analysis_id', $analysis->getKey())
        ->assertJsonPath(
            'meta.attribution.profit_estimate_id',
            $estimate->getKey(),
        )
        ->assertJsonPath('meta.report.status', 'calculated')
        ->assertJsonPath(
            'meta.report.metrics.purchase_price.expected_minor',
            10000,
        )
        ->assertJsonPath(
            'meta.report.metrics.purchase_price.actual_minor',
            10000,
        )
        ->assertJsonPath(
            'meta.report.metrics.purchase_price.signed_error_minor',
            0,
        )
        ->assertJsonPath(
            'meta.report.metrics.additional_costs.expected_minor',
            3000,
        )
        ->assertJsonPath(
            'meta.report.metrics.additional_costs.actual_minor',
            3000,
        )
        ->assertJsonPath(
            'meta.report.metrics.sale_price.signed_error_minor',
            $expectedSaleError,
        )
        ->assertJsonPath(
            'meta.report.metrics.net_profit.signed_error_minor',
            $expectedProfitError,
        )
        ->assertJsonPath('meta.report.duration.expected_seconds', null)
        ->assertJsonPath(
            'meta.report.duration.actual_seconds',
            $realized->sale_duration_seconds,
        )
        ->assertJsonPath('data.accuracy_available', true);
    $attributionId = $response->json('meta.attribution.id');
    $reportId = $response->json('meta.report.id');

    $this->actingAs($owner)
        ->postJson(
            route(
                'api.v1.owned-products.outcomes.estimate-attributions.store',
                $product,
            ),
            $payload,
        )
        ->assertOk()
        ->assertJsonPath('meta.created', false)
        ->assertJsonPath('meta.attribution.id', $attributionId)
        ->assertJsonPath('meta.report.id', $reportId);
    $this->actingAs($owner)
        ->postJson(
            route(
                'api.v1.owned-products.outcomes.estimate-attributions.store',
                $product,
            ),
            [...$payload, 'reason_code' => 'different_reason'],
        )
        ->assertConflict()
        ->assertJsonPath('code', 'outcome_idempotency_conflict');
    $this->actingAs($owner)
        ->postJson(
            route(
                'api.v1.owned-products.outcomes.estimate-attributions.store',
                $product,
            ),
            [
                ...$payload,
                'idempotency_key' => (string) Str::uuid(),
            ],
        )
        ->assertConflict()
        ->assertJsonPath('code', 'estimate_attribution_stale_state');
    $this->actingAs($owner)
        ->postJson(
            route(
                'api.v1.owned-products.outcomes.estimate-attributions.store',
                $product,
            ),
            [
                ...$payload,
                'expected_current_attribution_id' => $attributionId,
                'expected_current_accuracy_report_id' => $reportId,
                'correction_reason' => null,
                'idempotency_key' => (string) Str::uuid(),
            ],
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('correction_reason');
    $this->actingAs($owner)
        ->postJson(
            route(
                'api.v1.owned-products.outcomes.estimate-attributions.store',
                $product,
            ),
            [
                ...$payload,
                'expected_current_attribution_id' => $attributionId,
                'expected_current_accuracy_report_id' => $reportId,
                'correction_reason' => 'attribution_reconfirmed',
                'idempotency_key' => (string) Str::uuid(),
            ],
        )
        ->assertCreated()
        ->assertJsonPath('meta.attribution.sequence', 2)
        ->assertJsonPath(
            'meta.attribution.previous_attribution_id',
            $attributionId,
        )
        ->assertJsonPath('meta.report.previous_report_id', $reportId);

    expect(OutcomeEstimateAttribution::query()->count())->toBe(2)
        ->and(EstimateAccuracyReport::query()->count())->toBe(2)
        ->and(fn () => OutcomeEstimateAttribution::query()
            ->firstOrFail()
            ->update(['note' => 'rewritten']))
        ->toThrow(LogicException::class)
        ->and(fn () => EstimateAccuracyReport::query()
            ->firstOrFail()
            ->delete())
        ->toThrow(LogicException::class);
});

test('estimate accuracy candidates and commands remain tenant and role isolated', function () {
    [$owner, $organization] = portfolioWorkspace();
    [$viewer] = portfolioWorkspace(OrganizationRole::Viewer);
    OrganizationMembership::query()
        ->where('user_id', $viewer->getKey())
        ->update(['organization_id' => $organization->getKey()]);
    $viewer->update([
        'current_organization_id' => $organization->getKey(),
    ]);
    [$outsider, $outsideOrganization] = portfolioWorkspace();
    $product = portfolioProduct($this, $owner);
    $realized = accuracyCompleteOutcome($this, $owner, $product);
    $analysis = accuracyReadyAnalysis($owner, $organization);
    $outsideAnalysis = accuracyReadyAnalysis(
        $outsider,
        $outsideOrganization,
    );
    $payload = [
        'expected_current_attribution_id' => null,
        'expected_current_accuracy_report_id' => null,
        'realized_profit_id' => $realized->getKey(),
        'analysis_id' => $analysis->getKey(),
        'profit_estimate_id' => $analysis
            ->currentProfitEstimate()
            ->valueOrFail('id'),
        'reason_code' => 'original_buy_estimate_confirmed',
        'evidence_kind' => 'manual_confirmation',
        'evidence_reference' => null,
        'correction_reason' => null,
        'note' => null,
        'idempotency_key' => (string) Str::uuid(),
    ];

    $this->actingAs($viewer)
        ->getJson(route(
            'api.v1.owned-products.outcomes.estimate-candidates.index',
            $product,
        ))
        ->assertOk()
        ->assertJsonPath('meta.count', 1);
    $this->actingAs($viewer)
        ->postJson(
            route(
                'api.v1.owned-products.outcomes.estimate-attributions.store',
                $product,
            ),
            $payload,
        )
        ->assertForbidden();
    $this->actingAs($outsider)
        ->getJson(route(
            'api.v1.owned-products.outcomes.estimate-candidates.index',
            $product,
        ))
        ->assertNotFound();
    $this->actingAs($owner)
        ->postJson(
            route(
                'api.v1.owned-products.outcomes.estimate-attributions.store',
                $product,
            ),
            [
                ...$payload,
                'analysis_id' => $outsideAnalysis->getKey(),
                'profit_estimate_id' => $outsideAnalysis
                    ->currentProfitEstimate()
                    ->valueOrFail('id'),
                'idempotency_key' => (string) Str::uuid(),
            ],
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('profit_estimate_id');

    expect(OutcomeEstimateAttribution::query()->count())->toBe(0)
        ->and(EstimateAccuracyReport::query()->count())->toBe(0);
});
