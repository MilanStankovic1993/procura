<?php

use App\Actions\Markets\RecordExchangeRate;
use App\Actions\Markets\SyncMarketReferenceData;
use App\Enums\Organizations\OrganizationRole;
use App\Filament\Resources\SellComparableMarketNormalizations\SellComparableMarketNormalizationResource as SellComparableMarketNormalizationAdminResource;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\OwnedProduct;
use App\Models\ProductAlias;
use App\Models\ProductCategory;
use App\Models\ProductModel;
use App\Models\SellComparableMarketNormalization;
use App\Models\SellComparableRecord;
use App\Models\SellComparableSelection;
use App\Models\SellPriceBand;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    app(SyncMarketReferenceData::class)->sync();
    config([
        'sell_price_intelligence.max_candidates' => 100,
        'sell_price_intelligence.max_selected' => 20,
        'sell_price_intelligence.minimum_selected' => 3,
        'sell_price_intelligence.algorithm_version' => (
            'deterministic-sell-price-bands:v2'
        ),
        'sell_price_intelligence.selector_version' => (
            'deterministic-sell-comparable-selector:v2'
        ),
        'sell_price_intelligence.normalization_history_limit' => 10,
    ]);
});

function sellIntelWorkspace(
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

function sellIntelCatalog(): ProductCategory
{
    $category = ProductCategory::query()->create([
        'name' => 'Sell intelligence drills',
        'slug' => 'sell-intelligence-drills',
    ]);
    $brand = Brand::query()->create(['name' => 'Sell Intelligence Tools']);
    $model = ProductModel::query()->create([
        'brand_id' => $brand->getKey(),
        'product_category_id' => $category->getKey(),
        'name' => 'SI 18V-100',
        'model_number' => 'SI 18V-100',
        'canonical_key' => 'sell-intelligence:si-18v-100',
    ]);
    ProductAlias::query()->create([
        'product_model_id' => $model->getKey(),
        'alias' => 'SI 18V-100',
        'source' => 'sell_intelligence_test',
    ]);

    return $category;
}

function sellIntelProduct($test, User $owner): OwnedProduct
{
    $category = sellIntelCatalog();
    $id = $test->actingAs($owner)
        ->postJson(route('api.v1.owned-products.store'), [
            'product_category_id' => $category->getKey(),
            'brand_name' => 'Sell Intelligence Tools',
            'model_name' => 'SI 18V-100',
            'condition' => 'used_good',
            'age_months' => 24,
            'accessories' => ['case', 'charger'],
            'defects' => [],
            'purchase_history_known' => true,
            'purchase_history' => 'Original purchase confirmed.',
            'target_continent_code' => 'EU',
            'target_country_codes' => ['DE', 'FR'],
            'cross_border_preference' => 'cross_border_allowed',
            'desired_sale_speed' => 'balanced',
            'status' => 'ready',
            'notes' => null,
        ])
        ->assertCreated()
        ->json('data.id');
    $product = OwnedProduct::query()->findOrFail($id);
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

function sellIntelComparable(
    OwnedProduct $product,
    string $suffix,
    array $overrides = [],
): array {
    $assessment = $product->assessments()->firstOrFail();

    return [
        'owned_product_assessment_id' => $assessment->getKey(),
        'marketplace_source_key' => 'manual',
        'source_url' => "https://sell-market.example/items/{$suffix}",
        'external_id' => "sell-intel-{$suffix}",
        'marketplace_name' => 'Sell Market',
        'title' => "Sell Intelligence Tools SI 18V-100 {$suffix}",
        'description' => 'Manual asking-price evidence.',
        'listing_type' => 'product',
        'condition_code' => 'used_good',
        'seller_type' => 'private',
        'asking_price_minor' => 22000,
        'currency_code' => 'EUR',
        'country_code' => 'DE',
        'location' => 'Berlin',
        'included_accessories' => ['case', 'charger'],
        'missing_accessories' => [],
        'published_at' => null,
        'observed_at' => now()
            ->startOfSecond()
            ->subDay()
            ->toIso8601String(),
        ...$overrides,
    ];
}

test('manual sell evidence creates reproducible bands and replays idempotently', function () {
    [$owner] = sellIntelWorkspace();
    $product = sellIntelProduct($this, $owner);
    $baseTime = now()->startOfSecond();
    $lastPayload = [];

    foreach ([20000, 22000, 24000] as $index => $amount) {
        $lastPayload = sellIntelComparable(
            $product,
            (string) ($index + 1),
            [
                'asking_price_minor' => $amount,
                'observed_at' => $baseTime
                    ->copy()
                    ->subDays(3 - $index)
                    ->toIso8601String(),
            ],
        );
        $response = $this->actingAs($owner)->postJson(
            route('api.v1.owned-products.comparables.store', $product),
            $lastPayload,
        );
        $response
            ->assertCreated()
            ->assertJsonPath('meta.created', true)
            ->assertJsonPath(
                'data.price_band.status',
                $index < 2 ? 'needs_input' : 'ready',
            );
    }

    $this->actingAs($owner)
        ->postJson(
            route('api.v1.owned-products.comparables.store', $product),
            $lastPayload,
        )
        ->assertOk()
        ->assertJsonPath('meta.created', false)
        ->assertJsonPath('data.price_band.bands.quick_sale.low_minor', 20000)
        ->assertJsonPath('data.price_band.bands.quick_sale.high_minor', 22000)
        ->assertJsonPath('data.price_band.bands.recommended.low_minor', 20000)
        ->assertJsonPath('data.price_band.bands.recommended.high_minor', 24000)
        ->assertJsonPath('data.price_band.bands.ambitious.low_minor', 22000)
        ->assertJsonPath('data.price_band.bands.ambitious.high_minor', 24000)
        ->assertJsonPath('data.price_band.included_count', 3);

    expect(SellComparableRecord::query()->count())->toBe(3)
        ->and(SellComparableSelection::query()->count())->toBe(6)
        ->and(SellPriceBand::query()->count())->toBe(6);

    $band = SellPriceBand::query()
        ->where('target_country_code', 'DE')
        ->where('target_currency_code', 'EUR')
        ->with('items')
        ->orderByDesc('run_number')
        ->firstOrFail();
    expect($band->input_hash)->toHaveLength(64)
        ->and($band->items)->toHaveCount(3)
        ->and($band->reason_codes)
        ->toContain('asking_price_evidence_only')
        ->and($band->unknown_facts)
        ->toContain('realized_transaction_prices');
    expect(fn () => $band->update(['run_number' => 99]))
        ->toThrow(LogicException::class);
    expect(fn () => $band->delete())->toThrow(LogicException::class);
    expect(fn () => SellComparableRecord::query()->firstOrFail()->delete())
        ->toThrow(LogicException::class);
});

test('selection keeps explicit currency exclusions and never mixes evidence', function () {
    [$owner] = sellIntelWorkspace();
    $product = sellIntelProduct($this, $owner);
    $baseTime = now()->startOfSecond();

    foreach (range(1, 3) as $index) {
        $this->actingAs($owner)->postJson(
            route('api.v1.owned-products.comparables.store', $product),
            sellIntelComparable($product, "eur-{$index}", [
                'asking_price_minor' => 18000 + ($index * 1000),
                'observed_at' => $baseTime
                    ->copy()
                    ->subDays($index)
                    ->toIso8601String(),
            ]),
        )->assertCreated();
    }

    $this->actingAs($owner)
        ->postJson(
            route('api.v1.owned-products.comparables.store', $product),
            sellIntelComparable($product, 'usd-1', [
                'asking_price_minor' => 21000,
                'currency_code' => 'USD',
                'observed_at' => $baseTime->toIso8601String(),
            ]),
        )
        ->assertCreated()
        ->assertJsonPath('data.selection.status', 'insufficient')
        ->assertJsonPath('data.selection.included_count', 1)
        ->assertJsonPath('data.selection.excluded_count', 3)
        ->assertJsonPath('data.price_band.status', 'needs_input')
        ->assertJsonFragment(['currency_conversion_unavailable']);

    $refreshedEuroSelection = SellComparableSelection::query()
        ->where('target_country_code', 'DE')
        ->where('target_currency_code', 'EUR')
        ->orderByDesc('run_number')
        ->firstOrFail();
    expect($refreshedEuroSelection->included_count)->toBe(3)
        ->and($refreshedEuroSelection->excluded_count)->toBe(1);
    $this->actingAs($owner)
        ->getJson(
            route('api.v1.owned-products.sell-intelligence.show', $product),
        )
        ->assertOk()
        ->assertJsonCount(3, 'data.current_price_bands');
});

test('explicit cross market Sell evidence produces reproducible normalized bands', function () {
    [$owner] = sellIntelWorkspace();
    $product = sellIntelProduct($this, $owner);
    $normalizationAt = CarbonImmutable::now()->startOfSecond();
    $rate = app(RecordExchangeRate::class)->record(
        baseCurrencyCode: 'USD',
        quoteCurrencyCode: 'EUR',
        rateValue: '0.900000000000000000',
        provider: 'approved-manual',
        providerReference: 'sell-usd-eur-cross-market-test',
        evidenceHash: str_repeat('8', 64),
        effectiveAt: $normalizationAt->subHour(),
        publishedAt: $normalizationAt->subHour(),
        fetchedAt: $normalizationAt->subMinutes(30),
        rawEvidence: ['fixture' => 'Sell USD/EUR 0.9'],
    )['rate'];
    $recordIds = [];

    foreach ([20000, 21000, 22000] as $index => $amount) {
        $recordIds[] = $this->actingAs($owner)
            ->postJson(
                route('api.v1.owned-products.comparables.store', $product),
                sellIntelComparable($product, 'fr-usd-'.($index + 1), [
                    'asking_price_minor' => $amount,
                    'currency_code' => 'USD',
                    'country_code' => 'FR',
                    'location' => 'Paris',
                    'observed_at' => $normalizationAt
                        ->subDays(3 - $index)
                        ->toIso8601String(),
                ]),
            )
            ->assertCreated()
            ->json('data.record.id');
    }

    $sourceSelection = SellComparableSelection::query()
        ->where('target_country_code', 'FR')
        ->where('target_currency_code', 'USD')
        ->orderByDesc('run_number')
        ->firstOrFail();
    expect($sourceSelection->included_count)->toBe(3);

    foreach ($recordIds as $index => $recordId) {
        $response = $this->actingAs($owner)
            ->postJson(
                route(
                    'api.v1.owned-products.comparables.market-normalizations.store',
                    [
                        'ownedProduct' => $product,
                        'comparable' => $recordId,
                    ],
                ),
                [
                    'target_country_code' => 'DE',
                    'target_currency_code' => 'EUR',
                    'compatibility_status' => 'compatible',
                    'market_factor_basis_points' => 11000,
                    'shipping_minor' => 1000,
                    'import_duty_minor' => 500,
                    'tax_minor' => 0,
                    'other_cost_minor' => 0,
                    'evidence_reference' => 'sell-ops-ticket-'.($index + 1),
                    'compatibility_note' => (
                        'Regional Sell compatibility verified.'
                    ),
                    'observed_at' => $normalizationAt->toIso8601String(),
                    'evidence_confirmed' => true,
                ],
            )
            ->assertCreated()
            ->assertJsonPath('meta.created', true)
            ->assertJsonPath('data.compatibility_status', 'compatible')
            ->assertJsonPath('data.exchange_rate.id', $rate->getKey());

        if ($index === 2) {
            $response
                ->assertJsonPath('data.converted_amount_minor', 19800)
                ->assertJsonPath('data.market_adjusted_amount_minor', 21780)
                ->assertJsonPath('data.normalized_amount_minor', 23280)
                ->assertJsonPath('meta.selection.status', 'ready')
                ->assertJsonPath('meta.price_band.status', 'ready')
                ->assertJsonPath(
                    'meta.price_band.bands.recommended.low_minor',
                    21300,
                )
                ->assertJsonPath(
                    'meta.price_band.bands.recommended.high_minor',
                    23280,
                );
        }
    }

    $targetBand = SellPriceBand::query()
        ->where('target_country_code', 'DE')
        ->where('target_currency_code', 'EUR')
        ->with(['items', 'selection.items'])
        ->orderByDesc('run_number')
        ->firstOrFail();
    expect($targetBand->included_count)->toBe(3)
        ->and($targetBand->items->pluck('asking_price_minor')->sort()->values()->all())
        ->toBe([21300, 22290, 23280])
        ->and($targetBand->items->every(
            fn ($item): bool => (
                $item->currency_code === 'EUR'
                && (
                    $item->evidence_snapshot['comparable_evidence']['market_normalization']['evidence_hash']
                        ?? null
                ) !== null
            ),
        ))->toBeTrue()
        ->and($targetBand->reason_codes)
        ->toContain('explicit_market_normalization_only')
        ->and(SellComparableMarketNormalization::query()->count())->toBe(3);

    $counts = [
        SellComparableMarketNormalization::query()->count(),
        SellComparableSelection::query()->count(),
        SellPriceBand::query()->count(),
    ];
    $this->actingAs($owner)
        ->postJson(
            route(
                'api.v1.owned-products.comparables.market-normalizations.store',
                [
                    'ownedProduct' => $product,
                    'comparable' => $recordIds[2],
                ],
            ),
            [
                'target_country_code' => 'DE',
                'target_currency_code' => 'EUR',
                'compatibility_status' => 'compatible',
                'market_factor_basis_points' => 11000,
                'shipping_minor' => 1000,
                'import_duty_minor' => 500,
                'tax_minor' => 0,
                'other_cost_minor' => 0,
                'evidence_reference' => 'sell-ops-ticket-3',
                'compatibility_note' => (
                    'Regional Sell compatibility verified.'
                ),
                'observed_at' => $normalizationAt->toIso8601String(),
                'evidence_confirmed' => true,
            ],
        )
        ->assertOk()
        ->assertJsonPath('meta.created', false);
    expect([
        SellComparableMarketNormalization::query()->count(),
        SellComparableSelection::query()->count(),
        SellPriceBand::query()->count(),
    ])->toBe($counts);

    $this->actingAs($owner)
        ->getJson(
            route('api.v1.owned-products.sell-intelligence.show', $product),
        )
        ->assertOk()
        ->assertJsonCount(1, 'data.records.0.market_normalizations')
        ->assertJsonCount(3, 'data.current_price_bands');

    $normalization = SellComparableMarketNormalization::query()
        ->firstOrFail();
    expect(fn () => $normalization->update([
        'compatibility_note' => 'Changed',
    ]))->toThrow(LogicException::class, 'immutable');

    $administrator = User::factory()->create();
    $administrator->forceFill([
        'email_verified_at' => now(),
        'is_super_admin' => true,
    ])->save();
    $administrator = $administrator->fresh();
    expect($administrator->canAccessPanel(Filament::getPanel('admin')))
        ->toBeTrue()
        ->and(Gate::forUser($administrator)->allows(
            'viewAny',
            SellComparableMarketNormalization::class,
        ))->toBeTrue();
    $this->actingAs($administrator, 'web')
        ->get(SellComparableMarketNormalizationAdminResource::getUrl())
        ->assertOk()
        ->assertSeeText('approved-manual')
        ->assertSeeText('sell-ops-ticket-3')
        ->assertSeeText('FR / USD -> DE / EUR')
        ->assertDontSee('raw_evidence');
});

test('newer compatibility evidence supersedes rejection without rewriting history', function () {
    [$owner] = sellIntelWorkspace();
    $product = sellIntelProduct($this, $owner);
    $recordId = $this->actingAs($owner)
        ->postJson(
            route('api.v1.owned-products.comparables.store', $product),
            sellIntelComparable($product, 'fr-eur-compatibility', [
                'country_code' => 'FR',
                'currency_code' => 'EUR',
                'location' => 'Paris',
            ]),
        )
        ->assertCreated()
        ->json('data.record.id');
    $observedAt = now()->startOfSecond();
    $route = route(
        'api.v1.owned-products.comparables.market-normalizations.store',
        ['ownedProduct' => $product, 'comparable' => $recordId],
    );

    $this->actingAs($owner)
        ->postJson($route, [
            'target_country_code' => 'DE',
            'target_currency_code' => 'EUR',
            'compatibility_status' => 'incompatible',
            'evidence_reference' => 'sell-incompatible-review',
            'compatibility_note' => 'Regional version is not compatible.',
            'observed_at' => $observedAt->subMinute()->toIso8601String(),
            'evidence_confirmed' => true,
        ])
        ->assertCreated()
        ->assertJsonPath('meta.selection.included_count', 0)
        ->assertJsonFragment(['market_compatibility_rejected']);

    $this->actingAs($owner)
        ->postJson($route, [
            'target_country_code' => 'DE',
            'target_currency_code' => 'EUR',
            'compatibility_status' => 'compatible',
            'market_factor_basis_points' => 10000,
            'shipping_minor' => 0,
            'import_duty_minor' => 0,
            'tax_minor' => 0,
            'other_cost_minor' => 0,
            'evidence_reference' => 'sell-compatible-review',
            'compatibility_note' => 'A newer review confirmed compatibility.',
            'observed_at' => $observedAt->toIso8601String(),
            'evidence_confirmed' => true,
        ])
        ->assertCreated()
        ->assertJsonPath('data.exchange_rate.direction', 'identity')
        ->assertJsonPath('data.normalized_amount_minor', 22000)
        ->assertJsonPath('meta.selection.included_count', 1)
        ->assertJsonFragment(['cross_country_normalized']);

    expect(SellComparableMarketNormalization::query()->count())->toBe(2);
    config(['sell_price_intelligence.normalization_history_limit' => 1]);
    $this->actingAs($owner)
        ->getJson(
            route('api.v1.owned-products.sell-intelligence.show', $product),
        )
        ->assertOk()
        ->assertJsonCount(1, 'data.records.0.market_normalizations')
        ->assertJsonPath(
            'data.records.0.market_normalizations.0.evidence_reference',
            'sell-compatible-review',
        );

    $selection = SellComparableSelection::query()
        ->where('target_country_code', 'DE')
        ->where('target_currency_code', 'EUR')
        ->with('items')
        ->orderByDesc('run_number')
        ->firstOrFail();
    expect(
        $selection->items->firstOrFail()
            ->evidence_snapshot['market_normalization']['evidence_reference'],
    )->toBe('sell-compatible-review');
});

test('stale assessment is rejected and current projections are version safe', function () {
    [$owner] = sellIntelWorkspace();
    $product = sellIntelProduct($this, $owner);
    $payload = sellIntelComparable($product, 'stale');

    $this->actingAs($owner)
        ->postJson(
            route('api.v1.owned-products.comparables.store', $product),
            $payload,
        )
        ->assertCreated();
    $this->actingAs($owner)
        ->getJson(
            route('api.v1.owned-products.sell-intelligence.show', $product),
        )
        ->assertOk()
        ->assertJsonPath('data.assessment_current', true)
        ->assertJsonCount(2, 'data.current_price_bands');

    config([
        'sell_price_intelligence.algorithm_version' => (
            'deterministic-sell-price-bands:v-next'
        ),
    ]);
    $this->actingAs($owner)
        ->getJson(
            route('api.v1.owned-products.sell-intelligence.show', $product),
        )
        ->assertOk()
        ->assertJsonCount(0, 'data.current_price_bands')
        ->assertJsonCount(2, 'data.price_bands');
    config([
        'sell_price_intelligence.algorithm_version' => (
            'deterministic-sell-price-bands:v2'
        ),
    ]);

    $this->actingAs($owner)
        ->patchJson(route('api.v1.owned-products.update', $product), [
            'notes' => 'Intake changed after price evidence.',
        ])
        ->assertOk();
    $this->actingAs($owner)
        ->postJson(
            route('api.v1.owned-products.comparables.store', $product),
            $payload,
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('owned_product_assessment_id');
    $this->actingAs($owner)
        ->getJson(
            route('api.v1.owned-products.sell-intelligence.show', $product),
        )
        ->assertOk()
        ->assertJsonPath('data.assessment_current', false)
        ->assertJsonCount(0, 'data.current_price_bands')
        ->assertJsonCount(1, 'data.records');
});

test('sell intelligence enforces tenant and role boundaries', function () {
    [$owner, $organization] = sellIntelWorkspace();
    [$viewer] = sellIntelWorkspace(OrganizationRole::Viewer);
    OrganizationMembership::query()
        ->where('user_id', $viewer->getKey())
        ->update(['organization_id' => $organization->getKey()]);
    $viewer->update(['current_organization_id' => $organization->getKey()]);
    [$outsider] = sellIntelWorkspace();
    $product = sellIntelProduct($this, $owner);
    $payload = sellIntelComparable($product, 'authorization');

    $this->actingAs($viewer)
        ->getJson(
            route('api.v1.owned-products.sell-intelligence.show', $product),
        )
        ->assertOk();
    $this->actingAs($viewer)
        ->postJson(
            route('api.v1.owned-products.comparables.store', $product),
            $payload,
        )
        ->assertForbidden();
    $this->actingAs($outsider)
        ->getJson(
            route('api.v1.owned-products.sell-intelligence.show', $product),
        )
        ->assertNotFound();
    $this->actingAs($outsider)
        ->postJson(
            route('api.v1.owned-products.comparables.store', $product),
            $payload,
        )
        ->assertNotFound();

    expect(SellComparableRecord::query()->count())->toBe(0);
});
