<?php

use App\Actions\Analyses\CreateBuyAnalysisDraft;
use App\Actions\Analyses\RefreshComparableSelection;
use App\Actions\Analyses\RunBuyAnalysis;
use App\Actions\Analyses\SubmitAnalysis;
use App\Actions\Listings\CreateListing;
use App\Actions\Markets\RecordExchangeRate;
use App\Actions\Markets\SyncMarketReferenceData;
use App\Enums\Analyses\AnalysisStatus;
use App\Enums\Comparables\ComparableDecision;
use App\Enums\Comparables\ComparableSetStatus;
use App\Enums\Listings\ListingImageKind;
use App\Enums\Organizations\OrganizationRole;
use App\Enums\Pricing\PriceEstimateItemDecision;
use App\Enums\Pricing\PriceEstimateStatus;
use App\Filament\Resources\ComparableMarketNormalizations\ComparableMarketNormalizationResource;
use App\Models\Analysis;
use App\Models\Brand;
use App\Models\ComparableMarketNormalization;
use App\Models\ComparableRecord;
use App\Models\ComparableSet;
use App\Models\ExchangeRate;
use App\Models\Listing;
use App\Models\ListingImage;
use App\Models\MarketplaceSource;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\PriceEstimate;
use App\Models\ProductAlias;
use App\Models\ProductCategory;
use App\Models\ProductModel;
use App\Models\RiskAssessment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PlanSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    app(SyncMarketReferenceData::class)->sync();
    $this->seed(PlanSeeder::class);
    Queue::fake();
    config([
        'comparable_selection.max_candidates' => 100,
        'comparable_selection.max_selected' => 20,
        'comparable_selection.minimum_selected' => 3,
    ]);
});

function comparableWorkspace(
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

function comparableCatalog(): ProductModel
{
    $category = ProductCategory::query()->create([
        'name' => 'Impact Drivers',
        'slug' => 'comparable-impact-drivers',
    ]);
    $brand = Brand::query()->create(['name' => 'Comparable Tools']);
    $model = ProductModel::query()->create([
        'brand_id' => $brand->getKey(),
        'product_category_id' => $category->getKey(),
        'name' => 'CT 18V-100',
        'model_number' => 'CT 18V-100',
        'canonical_key' => 'comparable-tools:ct-18v-100',
    ]);
    ProductAlias::query()->create([
        'product_model_id' => $model->getKey(),
        'alias' => 'CT 18V-100',
        'source' => 'golden_test',
    ]);

    return $model;
}

function comparableListing(
    User $user,
    Organization $organization,
    string $title = 'Comparable Tools CT 18V-100 impact driver',
    array $overrides = [],
): Listing {
    $listing = app(CreateListing::class)->create(
        $organization,
        $user,
        MarketplaceSource::query()->where('key', 'manual')->firstOrFail(),
        [
            'source_url' => 'https://source.example/listings/target-product',
            'external_id' => 'target-product',
            'marketplace_name' => 'Source Market',
            'title' => $title,
            'description' => 'Used professional tool with case and charger.',
            'asking_price_minor' => 18999,
            'currency_code' => 'EUR',
            'seller_information' => 'Private seller.',
            'location' => 'Berlin',
            'source_country_code' => 'DE',
            'target_country_code' => 'DE',
            'status' => 'active',
            'notes' => null,
            ...$overrides,
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
        'checksum_sha256' => str_repeat('c', 64),
        'position' => 1,
    ]);

    return $listing;
}

function comparableRun(
    User $user,
    Organization $organization,
    Listing $listing,
): Analysis {
    $analysis = app(CreateBuyAnalysisDraft::class)->create(
        $organization,
        $user,
        $listing->getKey(),
        'DE',
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

function comparableInput(string $suffix, array $overrides = []): array
{
    return [
        'marketplace_source_key' => 'manual',
        'source_url' => "https://market.example/listing/{$suffix}",
        'external_id' => "comparable-{$suffix}",
        'marketplace_name' => 'Comparable Market',
        'title' => "Comparable Tools CT 18V-100 {$suffix}",
        'description' => 'Preserved manual comparable source facts.',
        'listing_type' => 'product',
        'condition_code' => 'used_good',
        'seller_type' => 'private',
        'asking_price_minor' => 20000,
        'currency_code' => 'EUR',
        'country_code' => 'DE',
        'location' => 'Berlin',
        'included_accessories' => ['case', 'charger'],
        'missing_accessories' => [],
        'published_at' => null,
        'observed_at' => now()->subDay()->startOfSecond()->toIso8601String(),
        ...$overrides,
    ];
}

test('manual evidence progresses an analysis to a ready idempotent comparable set', function () {
    [$owner, $organization] = comparableWorkspace();
    comparableCatalog();
    $analysis = comparableRun(
        $owner,
        $organization,
        comparableListing($owner, $organization),
    );

    expect($analysis->status)->toBe(AnalysisStatus::NeedsInput)
        ->and($analysis->result_payload['needs_input'])
        ->toContain('no_comparable_records')
        ->and($analysis->currentComparableSet()->firstOrFail()->status)
        ->toBe(ComparableSetStatus::Insufficient)
        ->and($analysis->currentRiskAssessment()->first())
        ->toBeNull();

    $observationBase = now()->startOfSecond();

    foreach (range(1, 3) as $index) {
        $this->actingAs($owner)
            ->postJson(
                route('api.v1.analyses.comparables.store', $analysis),
                comparableInput((string) $index, [
                    'asking_price_minor' => 20000 + ($index * 1000),
                    'observed_at' => $observationBase
                        ->copy()
                        ->subDays(4 - $index)
                        ->toIso8601String(),
                ]),
            )
            ->assertCreated()
            ->assertJsonPath('meta.created', true)
            ->assertJsonPath('data.product_model_id', $analysis->currentProductMatch()->valueOrFail('product_model_id'));
    }

    $duplicatePayload = comparableInput('3', [
        'asking_price_minor' => 23000,
        'observed_at' => $observationBase
            ->copy()
            ->subDay()
            ->toIso8601String(),
    ]);
    $this->actingAs($owner)
        ->postJson(
            route('api.v1.analyses.comparables.store', $analysis),
            $duplicatePayload,
        )
        ->assertOk()
        ->assertJsonPath('meta.created', false)
        ->assertJsonPath('meta.comparable_set.status', 'ready');

    $analysis->refresh();
    $set = $analysis->currentComparableSet()->with('items')->firstOrFail();
    expect($analysis->status)->toBe(AnalysisStatus::Completed)
        ->and($analysis->result_payload['needs_input'])->toBe([])
        ->and($analysis->result_payload['completed_steps'])
        ->toContain('comparable_selection')
        ->toContain('price_estimation')
        ->toContain('risk_assessment')
        ->and($analysis->result_payload['pending_steps'])
        ->not->toContain('comparable_selection')
        ->not->toContain('price_estimation')
        ->not->toContain('risk_assessment')
        ->and($set->status)->toBe(ComparableSetStatus::Ready)
        ->and($set->included_count)->toBe(3)
        ->and($set->items->pluck('rank')->all())->toBe([1, 2, 3])
        ->and(ComparableRecord::query()->count())->toBe(3)
        ->and(ComparableSet::query()->count())->toBe(4)
        ->and(PriceEstimate::query()->count())->toBe(1)
        ->and(RiskAssessment::query()->count())->toBe(1);

    $estimate = $analysis->currentPriceEstimate()->with('items')->firstOrFail();
    expect($estimate->status)->toBe(PriceEstimateStatus::Estimated)
        ->and($estimate->estimate_low_minor)->toBe(21000)
        ->and($estimate->estimate_minor)->toBe(22000)
        ->and($estimate->estimate_high_minor)->toBe(23000)
        ->and($estimate->included_count)->toBe(3)
        ->and($estimate->outlier_count)->toBe(0)
        ->and($estimate->unresolved_count)->toBe(0)
        ->and($estimate->reason_codes)
        ->toContain('identity_currency_conversion_only');

    $risk = $analysis->currentRiskAssessment()->with('signals')->firstOrFail();
    expect($risk->price_estimate_id)->toBe($estimate->getKey())
        ->and($risk->product_match_id)
        ->toBe($analysis->currentProductMatch()->valueOrFail('id'))
        ->and($risk->comparable_set_id)->toBe($set->getKey())
        ->and($risk->score)->toBe(5)
        ->and($risk->level->value)->toBe('low')
        ->and($risk->confidence_level->value)->toBe('medium')
        ->and($risk->unknown_count)->toBe(4)
        ->and($risk->signal_count)->toBe(5)
        ->and($risk->signals->where('is_unknown', true)->sum('score_contribution'))
        ->toBe(0)
        ->and($risk->signals->pluck('code'))
        ->toContain('asking_price_below_observed_band')
        ->toContain('condition_not_verified')
        ->toContain('ownership_and_serial_not_verified')
        ->toContain('payment_protection_not_verified')
        ->toContain('shipping_and_returns_not_verified')
        ->and($risk->verification_actions)->not->toBeEmpty();

    $record = ComparableRecord::query()->firstOrFail();
    expect(fn () => $record->update(['title' => 'Changed']))
        ->toThrow(LogicException::class, 'immutable');

    $this->actingAs($owner)
        ->getJson(route('api.v1.analyses.show', $analysis))
        ->assertOk()
        ->assertJsonPath('data.comparable_set.status', 'ready')
        ->assertJsonPath('data.comparable_set.included_count', 3)
        ->assertJsonCount(3, 'data.comparable_set.items')
        ->assertJsonPath('data.price_estimate.status', 'estimated')
        ->assertJsonPath('data.price_estimate.estimate_minor', 22000)
        ->assertJsonCount(3, 'data.price_estimate.items')
        ->assertJsonPath('data.risk_assessment.score', 5)
        ->assertJsonPath('data.risk_assessment.level', 'low')
        ->assertJsonPath('data.risk_assessment.unknown_count', 4)
        ->assertJsonCount(5, 'data.risk_assessment.signals');
});

test('price estimation excludes MAD outliers and preserves reproducible evidence', function () {
    [$owner, $organization] = comparableWorkspace();
    comparableCatalog();
    $analysis = comparableRun(
        $owner,
        $organization,
        comparableListing($owner, $organization),
    );
    $prices = [10000, 10100, 10200, 10300, 90000];
    $observedAt = now()->subDay()->startOfSecond()->toIso8601String();

    foreach ($prices as $index => $price) {
        $this->actingAs($owner)
            ->postJson(
                route('api.v1.analyses.comparables.store', $analysis),
                comparableInput('price-'.($index + 1), [
                    'asking_price_minor' => $price,
                    'observed_at' => $observedAt,
                ]),
            )
            ->assertCreated();
    }

    $analysis->refresh();
    $estimate = $analysis->currentPriceEstimate()->with('items')->firstOrFail();
    $outlier = $estimate->items->first(
        fn ($item): bool => $item->decision
            === PriceEstimateItemDecision::Outlier,
    );

    expect($estimate->status)->toBe(PriceEstimateStatus::Estimated)
        ->and($estimate->input_count)->toBe(5)
        ->and($estimate->included_count)->toBe(4)
        ->and($estimate->outlier_count)->toBe(1)
        ->and($estimate->unresolved_count)->toBe(0)
        ->and($estimate->estimate_low_minor)->toBe(10050)
        ->and($estimate->estimate_minor)->toBe(10100)
        ->and($estimate->estimate_high_minor)->toBe(10250)
        ->and($estimate->median_minor)->toBe(10150)
        ->and($estimate->mad_minor)->toBe(100)
        ->and($estimate->reason_codes)->toContain('extreme_outliers_excluded')
        ->and($outlier)->not->toBeNull()
        ->and($outlier->original_amount_minor)->toBe(90000)
        ->and($outlier->reason_codes)
        ->toContain('median_absolute_deviation_outlier')
        ->and(PriceEstimate::query()->count())->toBe(3)
        ->and(RiskAssessment::query()->count())->toBe(3);

    $this->actingAs($owner)
        ->postJson(
            route('api.v1.analyses.comparables.store', $analysis),
            comparableInput('price-5', [
                'asking_price_minor' => 90000,
                'observed_at' => $observedAt,
            ]),
        )
        ->assertOk()
        ->assertJsonPath('meta.created', false);

    expect(PriceEstimate::query()->count())->toBe(3)
        ->and(RiskAssessment::query()->count())->toBe(3);

    $record = PriceEstimate::query()->firstOrFail();
    expect(fn () => $record->update(['estimate_minor' => 1]))
        ->toThrow(LogicException::class, 'immutable');
    $risk = RiskAssessment::query()->firstOrFail();
    expect(fn () => $risk->update(['score' => 100]))
        ->toThrow(LogicException::class, 'immutable');

    [$outsider] = comparableWorkspace();
    $this->actingAs($outsider)
        ->getJson(route('api.v1.analyses.show', $analysis))
        ->assertNotFound();
});

test('price estimation exposes high dispersion as low confidence without inventing adjustments', function () {
    [$owner, $organization] = comparableWorkspace();
    comparableCatalog();
    $analysis = comparableRun(
        $owner,
        $organization,
        comparableListing($owner, $organization),
    );

    foreach ([10000, 50000, 90000] as $index => $price) {
        $this->actingAs($owner)
            ->postJson(
                route('api.v1.analyses.comparables.store', $analysis),
                comparableInput('dispersed-'.($index + 1), [
                    'asking_price_minor' => $price,
                ]),
            )
            ->assertCreated();
    }

    $analysis->refresh();
    $estimate = $analysis->currentPriceEstimate()->firstOrFail();
    $risk = $analysis->currentRiskAssessment()->with('signals')->firstOrFail();

    expect($analysis->status)->toBe(AnalysisStatus::Completed)
        ->and($estimate->status)->toBe(PriceEstimateStatus::LowConfidence)
        ->and($estimate->estimate_low_minor)->toBe(10000)
        ->and($estimate->estimate_minor)->toBe(50000)
        ->and($estimate->estimate_high_minor)->toBe(90000)
        ->and($estimate->dispersion_basis_points)->toBe(16000)
        ->and($estimate->reason_codes)->toContain('high_price_dispersion')
        ->and($analysis->result_payload['price_estimate']['status'])
        ->toBe('low_confidence')
        ->and(PriceEstimate::query()->count())->toBe(1)
        ->and($risk->score)->toBe(10)
        ->and($risk->level->value)->toBe('low')
        ->and($risk->signals->pluck('code'))
        ->toContain('price_evidence_low_confidence')
        ->and($risk->signals->firstWhere(
            'code',
            'price_evidence_low_confidence',
        )?->score_contribution)->toBe(10);
});

test('risk assessment applies bounded price and cross border rules without scoring unknown facts', function () {
    [$owner, $organization] = comparableWorkspace();
    comparableCatalog();
    $analysis = comparableRun(
        $owner,
        $organization,
        comparableListing(
            $owner,
            $organization,
            overrides: [
                'asking_price_minor' => 4000,
                'source_country_code' => 'AT',
            ],
        ),
    );

    foreach ([10000, 11000, 12000] as $index => $price) {
        $this->actingAs($owner)
            ->postJson(
                route('api.v1.analyses.comparables.store', $analysis),
                comparableInput('risk-'.($index + 1), [
                    'asking_price_minor' => $price,
                ]),
            )
            ->assertCreated();
    }

    $assessment = $analysis->fresh()
        ->currentRiskAssessment()
        ->with('signals')
        ->firstOrFail();
    $priceSignal = $assessment->signals->firstWhere(
        'code',
        'asking_price_below_observed_band',
    );
    $crossBorderSignal = $assessment->signals->firstWhere(
        'code',
        'cross_border_transaction_context',
    );

    expect($assessment->score)->toBe(40)
        ->and($assessment->level->value)->toBe('medium')
        ->and($assessment->unknown_count)->toBe(4)
        ->and($assessment->signals->where('is_unknown', true)->sum('score_contribution'))
        ->toBe(0)
        ->and($priceSignal?->score_contribution)->toBe(30)
        ->and($priceSignal?->evidence_snapshot['deviation_basis_points'])
        ->toBe(6000)
        ->and($crossBorderSignal?->score_contribution)->toBe(10)
        ->and($crossBorderSignal?->evidence_snapshot)
        ->toMatchArray([
            'source_country_code' => 'AT',
            'target_country_code' => 'DE',
        ])
        ->and($assessment->verification_actions)
        ->toContain(
            'Verify shipping, customs, tax, returns, and regional compatibility before purchase.',
        );
});

test('selection preserves explicit exclusion evidence for unsafe market mixing', function () {
    [$owner, $organization] = comparableWorkspace();
    comparableCatalog();
    $analysis = comparableRun(
        $owner,
        $organization,
        comparableListing($owner, $organization),
    );
    $cases = [
        'spare' => [
            'listing_type' => 'spare_part',
        ],
        'currency' => [
            'currency_code' => 'USD',
        ],
        'country' => [
            'country_code' => 'FR',
        ],
        'broken' => [
            'condition_code' => 'broken',
        ],
    ];

    foreach ($cases as $suffix => $overrides) {
        $this->actingAs($owner)
            ->postJson(
                route('api.v1.analyses.comparables.store', $analysis),
                comparableInput($suffix, $overrides),
            )
            ->assertCreated();
    }

    $set = $analysis->fresh()->currentComparableSet()->with('items')->firstOrFail();
    $reasons = $set->items->flatMap->reason_codes->unique()->values()->all();
    expect($set->status)->toBe(ComparableSetStatus::Insufficient)
        ->and($set->included_count)->toBe(0)
        ->and($set->excluded_count)->toBe(4)
        ->and($set->items->every(
            fn ($item): bool => $item->decision === ComparableDecision::Excluded,
        ))->toBeTrue()
        ->and($reasons)->toContain('spare_part_listing')
        ->and($reasons)->toContain('currency_conversion_unavailable')
        ->and($reasons)->toContain('cross_country_normalization_unavailable')
        ->and($reasons)->toContain('broken_condition');
});

test('selection deduplicates source history and hard bounds the candidate pool', function () {
    config(['comparable_selection.max_candidates' => 3]);
    [$owner, $organization] = comparableWorkspace();
    comparableCatalog();
    $analysis = comparableRun(
        $owner,
        $organization,
        comparableListing($owner, $organization),
    );

    $firstObservation = comparableInput('history', [
        'asking_price_minor' => 18000,
        'observed_at' => now()->subDays(3)->startOfSecond()->toIso8601String(),
    ]);
    $secondObservation = [
        ...$firstObservation,
        'asking_price_minor' => 17500,
        'observed_at' => now()->subDays(2)->startOfSecond()->toIso8601String(),
    ];

    foreach ([
        $firstObservation,
        $secondObservation,
        comparableInput('other-a'),
        comparableInput('other-b', [
            'observed_at' => now()->subHours(12)->startOfSecond()->toIso8601String(),
        ]),
    ] as $payload) {
        $this->actingAs($owner)
            ->postJson(
                route('api.v1.analyses.comparables.store', $analysis),
                $payload,
            )
            ->assertCreated();
    }

    $set = $analysis->fresh()->currentComparableSet()->with('items')->firstOrFail();
    expect(ComparableRecord::query()->count())->toBe(4)
        ->and($set->candidate_count)->toBe(3)
        ->and($set->reason_codes)->toContain('candidate_pool_truncated');

    config(['comparable_selection.max_candidates' => 100]);
    app(RefreshComparableSelection::class)->refresh(
        $analysis->fresh(),
        $analysis->currentProductMatch()->firstOrFail(),
    );
    $historySet = $analysis->fresh()->currentComparableSet()->with('items')->firstOrFail();
    $superseded = $historySet->items->first(
        fn ($item): bool => in_array(
            'superseded_source_observation',
            $item->reason_codes,
            true,
        ),
    );
    expect($historySet->candidate_count)->toBe(4)
        ->and($historySet->included_count)->toBe(3)
        ->and($superseded)->not->toBeNull();
});

test('comparable APIs are validated bounded role aware and tenant isolated', function () {
    [$owner, $organization] = comparableWorkspace();
    comparableCatalog();
    $analysis = comparableRun(
        $owner,
        $organization,
        comparableListing($owner, $organization),
    );
    [$outsider] = comparableWorkspace();
    $viewer = User::factory()->create();
    OrganizationMembership::factory()->create([
        'organization_id' => $organization,
        'user_id' => $viewer,
        'role' => OrganizationRole::Viewer,
    ]);
    $viewer->update(['current_organization_id' => $organization->getKey()]);

    $this->actingAs($owner)
        ->postJson(
            route('api.v1.analyses.comparables.store', $analysis),
            comparableInput('invalid', [
                'source_url' => null,
                'external_id' => null,
            ]),
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['source_url', 'external_id']);
    $this->actingAs($owner)
        ->getJson(route('api.v1.analyses.comparables.index', [
            'analysis' => $analysis,
            'limit' => 51,
        ]))
        ->assertUnprocessable();
    $this->actingAs($viewer)
        ->getJson(route('api.v1.analyses.comparables.index', $analysis))
        ->assertOk();
    $this->actingAs($viewer)
        ->postJson(
            route('api.v1.analyses.comparables.store', $analysis),
            comparableInput('viewer'),
        )
        ->assertForbidden();
    $this->actingAs($outsider)
        ->getJson(route('api.v1.analyses.comparables.index', $analysis))
        ->assertNotFound();
    $this->actingAs($outsider)
        ->postJson(
            route('api.v1.analyses.comparables.store', $analysis),
            comparableInput('outsider'),
        )
        ->assertNotFound();
});

test('manual comparables cannot bypass an unresolved canonical product match', function () {
    [$owner, $organization] = comparableWorkspace();
    $analysis = comparableRun(
        $owner,
        $organization,
        comparableListing($owner, $organization, 'Unknown ZXQ product'),
    );

    $this->actingAs($owner)
        ->postJson(
            route('api.v1.analyses.comparables.store', $analysis),
            comparableInput('unresolved'),
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('analysis');

    expect(ComparableRecord::query()->count())->toBe(0);
});

test('confirmed cross market evidence produces an exact reproducible normalized estimate', function () {
    [$owner, $organization] = comparableWorkspace();
    comparableCatalog();
    $analysis = comparableRun(
        $owner,
        $organization,
        comparableListing($owner, $organization),
    );
    $normalizationAt = CarbonImmutable::now()->startOfSecond();
    $rate = app(RecordExchangeRate::class)->record(
        baseCurrencyCode: 'USD',
        quoteCurrencyCode: 'EUR',
        rateValue: '0.900000000000000000',
        provider: 'approved-manual',
        providerReference: 'usd-eur-cross-market-test',
        evidenceHash: str_repeat('9', 64),
        effectiveAt: $normalizationAt->copy()->subHour(),
        publishedAt: $normalizationAt->copy()->subHour(),
        fetchedAt: $normalizationAt->copy()->subMinutes(30),
        rawEvidence: ['fixture' => 'USD/EUR 0.9'],
    )['rate'];
    $recordIds = [];

    foreach ([20000, 21000, 22000] as $index => $price) {
        $recordIds[] = $this->actingAs($owner)
            ->postJson(
                route('api.v1.analyses.comparables.store', $analysis),
                comparableInput('cross-market-'.($index + 1), [
                    'asking_price_minor' => $price,
                    'currency_code' => 'USD',
                    'country_code' => 'US',
                    'location' => 'New York',
                ]),
            )
            ->assertCreated()
            ->json('data.id');
    }

    $excludedSet = $analysis->fresh()
        ->currentComparableSet()
        ->with('items')
        ->firstOrFail();
    expect($excludedSet->included_count)->toBe(0)
        ->and($excludedSet->items->flatMap->reason_codes)
        ->toContain('currency_conversion_unavailable')
        ->toContain('cross_country_normalization_unavailable');

    foreach ($recordIds as $index => $recordId) {
        $response = $this->actingAs($owner)
            ->postJson(
                route(
                    'api.v1.analyses.comparables.market-normalizations.store',
                    [
                        'analysis' => $analysis,
                        'comparable' => $recordId,
                    ],
                ),
                [
                    'compatibility_status' => 'compatible',
                    'market_factor_basis_points' => 11000,
                    'shipping_minor' => 1000,
                    'import_duty_minor' => 500,
                    'tax_minor' => 0,
                    'other_cost_minor' => 0,
                    'evidence_reference' => 'ops-ticket-'.($index + 1),
                    'compatibility_note' => 'Regional compatibility verified.',
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
                ->assertJsonPath('meta.comparable_set.status', 'ready');
        }
    }

    $analysis->refresh();
    $set = $analysis->currentComparableSet()->with('items')->firstOrFail();
    $estimate = $analysis->currentPriceEstimate()->with('items')->firstOrFail();
    $normalizedAmounts = $estimate->items
        ->pluck('target_amount_minor')
        ->sort()
        ->values()
        ->all();

    expect($set->status)->toBe(ComparableSetStatus::Ready)
        ->and($set->included_count)->toBe(3)
        ->and($set->items->flatMap->reason_codes)
        ->toContain('cross_country_normalized')
        ->toContain('dated_currency_normalized')
        ->and($set->items->every(
            fn ($item): bool => (
                $item->evidence_snapshot['market_normalization']['compatibility_status']
                    ?? null
            ) === 'compatible',
        ))->toBeTrue()
        ->and($estimate->status)->toBe(PriceEstimateStatus::Estimated)
        ->and($estimate->estimate_low_minor)->toBe(21300)
        ->and($estimate->estimate_minor)->toBe(22290)
        ->and($estimate->estimate_high_minor)->toBe(23280)
        ->and($normalizedAmounts)->toBe([21300, 22290, 23280])
        ->and($estimate->reason_codes)
        ->toContain('cross_market_normalization_applied')
        ->toContain('dated_exchange_rates_applied')
        ->and($estimate->items->every(
            fn ($item): bool => $item->exchange_rate_id === $rate->getKey(),
        ))->toBeTrue()
        ->and(ComparableMarketNormalization::query()->count())->toBe(3)
        ->and(ExchangeRate::query()->count())->toBe(1);

    $counts = [
        ComparableMarketNormalization::query()->count(),
        ComparableSet::query()->count(),
        PriceEstimate::query()->count(),
        RiskAssessment::query()->count(),
    ];
    $duplicate = $this->actingAs($owner)
        ->postJson(
            route(
                'api.v1.analyses.comparables.market-normalizations.store',
                [
                    'analysis' => $analysis,
                    'comparable' => $recordIds[2],
                ],
            ),
            [
                'compatibility_status' => 'compatible',
                'market_factor_basis_points' => 11000,
                'shipping_minor' => 1000,
                'import_duty_minor' => 500,
                'tax_minor' => 0,
                'other_cost_minor' => 0,
                'evidence_reference' => 'ops-ticket-3',
                'compatibility_note' => 'Regional compatibility verified.',
                'observed_at' => $normalizationAt->toIso8601String(),
                'evidence_confirmed' => true,
            ],
        )
        ->assertOk()
        ->assertJsonPath('meta.created', false);

    expect($duplicate->json('data.evidence_hash'))->toHaveLength(64)
        ->and([
            ComparableMarketNormalization::query()->count(),
            ComparableSet::query()->count(),
            PriceEstimate::query()->count(),
            RiskAssessment::query()->count(),
        ])->toBe($counts);

    $administrator = User::factory()->create();
    $administrator->forceFill([
        'email_verified_at' => now(),
        'is_super_admin' => true,
    ])->save();
    $administrator = $administrator->fresh();
    expect($administrator->canAccessPanel(Filament::getPanel('admin')))->toBeTrue()
        ->and(Gate::forUser($administrator)->allows(
            'viewAny',
            ComparableMarketNormalization::class,
        ))->toBeTrue();
    $this->actingAs($administrator, 'web')
        ->get(ComparableMarketNormalizationResource::getUrl())
        ->assertOk()
        ->assertSeeText('approved-manual')
        ->assertSeeText('ops-ticket-3')
        ->assertSeeText('US / USD -> DE / EUR')
        ->assertDontSee('raw_evidence');

    $normalization = ComparableMarketNormalization::query()->firstOrFail();
    expect(fn () => $normalization->update(['compatibility_note' => 'Changed']))
        ->toThrow(LogicException::class, 'immutable');
});

test('incompatible evidence remains excluded and a newer compatible fact supersedes it', function () {
    [$owner, $organization] = comparableWorkspace();
    comparableCatalog();
    $analysis = comparableRun(
        $owner,
        $organization,
        comparableListing($owner, $organization),
    );
    $recordId = $this->actingAs($owner)
        ->postJson(
            route('api.v1.analyses.comparables.store', $analysis),
            comparableInput('cross-country-eur', [
                'country_code' => 'FR',
                'location' => 'Paris',
            ]),
        )
        ->assertCreated()
        ->json('data.id');
    $observedAt = now()->startOfSecond();

    $this->actingAs($owner)
        ->postJson(
            route(
                'api.v1.analyses.comparables.market-normalizations.store',
                ['analysis' => $analysis, 'comparable' => $recordId],
            ),
            [
                'compatibility_status' => 'incompatible',
                'evidence_reference' => 'inspection-1',
                'compatibility_note' => 'The regional variant is not compatible.',
                'observed_at' => $observedAt->toIso8601String(),
                'evidence_confirmed' => true,
            ],
        )
        ->assertCreated()
        ->assertJsonPath('data.normalized_amount_minor', null);

    $incompatibleSet = $analysis->fresh()
        ->currentComparableSet()
        ->with('items')
        ->firstOrFail();
    expect($incompatibleSet->included_count)->toBe(0)
        ->and($incompatibleSet->items->first()->reason_codes)
        ->toContain('market_compatibility_rejected');

    $this->actingAs($owner)
        ->postJson(
            route(
                'api.v1.analyses.comparables.market-normalizations.store',
                ['analysis' => $analysis, 'comparable' => $recordId],
            ),
            [
                'compatibility_status' => 'compatible',
                'market_factor_basis_points' => 10000,
                'shipping_minor' => 0,
                'import_duty_minor' => 0,
                'tax_minor' => 0,
                'other_cost_minor' => 0,
                'evidence_reference' => 'inspection-2',
                'compatibility_note' => 'A later exact inspection confirmed compatibility.',
                'observed_at' => $observedAt->toIso8601String(),
                'evidence_confirmed' => true,
            ],
        )
        ->assertCreated()
        ->assertJsonPath('data.exchange_rate.direction', 'identity')
        ->assertJsonPath('data.normalized_amount_minor', 20000);

    $compatibleSet = $analysis->fresh()
        ->currentComparableSet()
        ->with('items')
        ->firstOrFail();
    expect(ComparableMarketNormalization::query()->count())->toBe(2)
        ->and($compatibleSet->included_count)->toBe(1)
        ->and($compatibleSet->items->first()->reason_codes)
        ->toContain('cross_country_normalized')
        ->toContain('same_currency');
});

test('market normalization validation and authorization fail closed', function () {
    [$owner, $organization] = comparableWorkspace();
    comparableCatalog();
    $analysis = comparableRun(
        $owner,
        $organization,
        comparableListing($owner, $organization),
    );
    $crossMarketId = $this->actingAs($owner)
        ->postJson(
            route('api.v1.analyses.comparables.store', $analysis),
            comparableInput('missing-rate', [
                'currency_code' => 'USD',
                'country_code' => 'US',
            ]),
        )
        ->assertCreated()
        ->json('data.id');
    $sameMarketId = $this->actingAs($owner)
        ->postJson(
            route('api.v1.analyses.comparables.store', $analysis),
            comparableInput('same-market'),
        )
        ->assertCreated()
        ->json('data.id');
    $payload = [
        'compatibility_status' => 'compatible',
        'market_factor_basis_points' => 10000,
        'shipping_minor' => 0,
        'import_duty_minor' => 0,
        'tax_minor' => 0,
        'other_cost_minor' => 0,
        'evidence_reference' => 'validation-test',
        'compatibility_note' => 'Validated compatibility evidence.',
        'observed_at' => now()->startOfSecond()->toIso8601String(),
        'evidence_confirmed' => true,
    ];

    $this->actingAs($owner)
        ->postJson(
            route(
                'api.v1.analyses.comparables.market-normalizations.store',
                ['analysis' => $analysis, 'comparable' => $crossMarketId],
            ),
            $payload,
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('exchange_rate');
    $this->actingAs($owner)
        ->postJson(
            route(
                'api.v1.analyses.comparables.market-normalizations.store',
                ['analysis' => $analysis, 'comparable' => $sameMarketId],
            ),
            $payload,
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('comparable');
    $this->actingAs($owner)
        ->postJson(
            route(
                'api.v1.analyses.comparables.market-normalizations.store',
                ['analysis' => $analysis, 'comparable' => $crossMarketId],
            ),
            [
                ...$payload,
                'market_factor_basis_points' => 20000,
                'evidence_confirmed' => false,
            ],
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'market_factor_basis_points',
            'evidence_confirmed',
        ]);
    $this->actingAs($owner)
        ->postJson(
            route(
                'api.v1.analyses.comparables.market-normalizations.store',
                ['analysis' => $analysis, 'comparable' => $crossMarketId],
            ),
            [
                ...$payload,
                'compatibility_status' => 'incompatible',
            ],
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'market_factor_basis_points',
            'shipping_minor',
            'import_duty_minor',
            'tax_minor',
            'other_cost_minor',
        ]);

    $viewer = User::factory()->create();
    OrganizationMembership::factory()->create([
        'organization_id' => $organization,
        'user_id' => $viewer,
        'role' => OrganizationRole::Viewer,
    ]);
    $viewer->update(['current_organization_id' => $organization->getKey()]);
    [$outsider] = comparableWorkspace();

    $this->actingAs($viewer)
        ->postJson(
            route(
                'api.v1.analyses.comparables.market-normalizations.store',
                ['analysis' => $analysis, 'comparable' => $crossMarketId],
            ),
            $payload,
        )
        ->assertForbidden();
    $this->actingAs($outsider)
        ->postJson(
            route(
                'api.v1.analyses.comparables.market-normalizations.store',
                ['analysis' => $analysis, 'comparable' => $crossMarketId],
            ),
            $payload,
        )
        ->assertNotFound();

    expect(ComparableMarketNormalization::query()->count())->toBe(0);
});
