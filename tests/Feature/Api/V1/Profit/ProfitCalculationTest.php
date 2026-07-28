<?php

use App\Actions\Analyses\ConfirmAnalysisCosts;
use App\Actions\Analyses\ConfirmOpportunityEvidence;
use App\Actions\Analyses\CreateAnalysisComparable;
use App\Actions\Analyses\CreateBuyAnalysisDraft;
use App\Actions\Analyses\RunBuyAnalysis;
use App\Actions\Analyses\SubmitAnalysis;
use App\Actions\Listings\CreateListing;
use App\Actions\Markets\SyncMarketReferenceData;
use App\Enums\Listings\ListingImageKind;
use App\Enums\Organizations\OrganizationRole;
use App\Enums\Profit\ProfitEstimateStatus;
use App\Models\Analysis;
use App\Models\Brand;
use App\Models\BuyerDecisionEvent;
use App\Models\CostInput;
use App\Models\DealScore;
use App\Models\DealScoreItem;
use App\Models\Listing;
use App\Models\ListingImage;
use App\Models\MarketplaceSource;
use App\Models\OpportunityAssessment;
use App\Models\OpportunityAssessmentItem;
use App\Models\OpportunityInput;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProductAlias;
use App\Models\ProductCategory;
use App\Models\ProductModel;
use App\Models\ProfitEstimate;
use App\Models\ProfitEstimateItem;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

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

function profitWorkspace(
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

function profitCatalog(): ProductModel
{
    $category = ProductCategory::query()->create([
        'name' => 'Profit Test Tools',
        'slug' => 'profit-test-tools',
    ]);
    $brand = Brand::query()->create(['name' => 'Profit Tools']);
    $model = ProductModel::query()->create([
        'brand_id' => $brand->getKey(),
        'product_category_id' => $category->getKey(),
        'name' => 'PT 18V-100',
        'model_number' => 'PT 18V-100',
        'canonical_key' => 'profit-tools:pt-18v-100',
    ]);
    ProductAlias::query()->create([
        'product_model_id' => $model->getKey(),
        'alias' => 'PT 18V-100',
        'source' => 'profit_golden_test',
    ]);

    return $model;
}

function profitListing(
    User $user,
    Organization $organization,
    string $sourceCountryCode = 'DE',
): Listing {
    $listing = app(CreateListing::class)->create(
        $organization,
        $user,
        MarketplaceSource::query()->where('key', 'manual')->firstOrFail(),
        [
            'source_url' => 'https://source.example/listings/profit-target',
            'external_id' => "profit-target-{$sourceCountryCode}",
            'marketplace_name' => 'Profit Source Market',
            'title' => 'Profit Tools PT 18V-100',
            'description' => 'Used professional tool with case and charger.',
            'asking_price_minor' => 15000,
            'currency_code' => 'EUR',
            'seller_information' => 'Verified private seller.',
            'location' => 'Vienna',
            'source_country_code' => $sourceCountryCode,
            'target_country_code' => 'DE',
            'status' => 'active',
            'notes' => null,
        ],
    );
    ListingImage::query()->create([
        'listing_id' => $listing->getKey(),
        'uploaded_by_user_id' => $user->getKey(),
        'kind' => ListingImageKind::Product,
        'disk' => 'local',
        'path' => "testing/{$listing->getKey()}/profit-product.png",
        'client_filename' => 'profit-product.png',
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

function profitComparableInput(int $index): array
{
    return [
        'product_variant_id' => null,
        'source_url' => "https://market.example/profit/{$index}",
        'external_id' => "profit-comparable-{$index}",
        'marketplace_name' => 'Profit Comparable Market',
        'title' => "Profit Tools PT 18V-100 {$index}",
        'description' => 'Preserved manual comparable source facts.',
        'listing_type' => 'product',
        'condition_code' => 'used_good',
        'seller_type' => 'private',
        'asking_price_minor' => 20000 + ($index * 1000),
        'currency_code' => 'EUR',
        'country_code' => 'DE',
        'location' => 'Berlin',
        'included_accessories' => ['case', 'charger'],
        'missing_accessories' => [],
        'published_at' => null,
        'observed_at' => now()
            ->subDays(4 - $index)
            ->startOfSecond()
            ->toIso8601String(),
    ];
}

function profitReadyAnalysis(
    User $user,
    Organization $organization,
    string $sourceCountryCode = 'DE',
): Analysis {
    profitCatalog();
    $analysis = app(CreateBuyAnalysisDraft::class)->create(
        $organization,
        $user,
        profitListing($user, $organization, $sourceCountryCode)->getKey(),
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
    $source = MarketplaceSource::query()
        ->where('key', 'manual')
        ->firstOrFail();

    foreach (range(1, 3) as $index) {
        app(CreateAnalysisComparable::class)->create(
            $organization,
            $user,
            $analysis->fresh(),
            $source,
            profitComparableInput($index),
        );
    }

    return $analysis->fresh();
}

function profitCostPayload(Analysis $analysis, array $overrides = []): array
{
    return [
        'price_estimate_id' => $analysis
            ->currentPriceEstimate()
            ->valueOrFail('id'),
        'risk_assessment_id' => $analysis
            ->currentRiskAssessment()
            ->valueOrFail('id'),
        'currency_code' => 'EUR',
        'purchase_price_minor' => 15000,
        'transport_minor' => 500,
        'repair_minor' => 1000,
        'platform_fees_minor' => 2200,
        'payment_fees_minor' => 300,
        'customs_minor' => 0,
        'tax_minor' => 0,
        'other_costs_minor' => 200,
        'safety_reserve_minor' => 500,
        'regional_compatibility_confirmed' => true,
        ...$overrides,
    ];
}

function opportunityReadyAnalysis(
    User $user,
    Organization $organization,
    string $sourceCountryCode = 'DE',
): Analysis {
    $analysis = profitReadyAnalysis(
        $user,
        $organization,
        $sourceCountryCode,
    );

    return app(ConfirmAnalysisCosts::class)->confirm(
        $organization,
        $user,
        $analysis,
        profitCostPayload($analysis),
    )['analysis'];
}

function opportunityPayload(Analysis $analysis, array $overrides = []): array
{
    $analysis->refresh();

    return [
        'comparable_set_id' => $analysis
            ->currentComparableSet()
            ->valueOrFail('id'),
        'price_estimate_id' => $analysis
            ->currentPriceEstimate()
            ->valueOrFail('id'),
        'risk_assessment_id' => $analysis
            ->currentRiskAssessment()
            ->valueOrFail('id'),
        'cost_input_id' => $analysis
            ->currentCostInput()
            ->valueOrFail('id'),
        'profit_estimate_id' => $analysis
            ->currentProfitEstimate()
            ->valueOrFail('id'),
        'shipping_method' => 'parcel',
        'shipping_distance_km' => 120,
        'pickup_available' => false,
        'tracking_available' => true,
        'insurance_available' => true,
        'packaging_confirmed' => true,
        'cross_border_handling_confirmed' => true,
        'sold_comparables_count' => 4,
        'median_days_to_sale' => 14,
        'observation_window_days' => 30,
        'demand_evidence_observed_at' => now()
            ->subDay()
            ->startOfSecond()
            ->toIso8601String(),
        'demand_evidence_source' => 'Verified sold-listing review.',
        ...$overrides,
    ];
}

test('complete explicit costs produce one reproducible idempotent profit estimate', function () {
    [$owner, $organization] = profitWorkspace();
    $analysis = profitReadyAnalysis($owner, $organization);
    $payload = profitCostPayload($analysis);

    $this->actingAs($owner)
        ->postJson(route('api.v1.analyses.costs.store', $analysis), $payload)
        ->assertCreated()
        ->assertJsonPath('meta.created', true)
        ->assertJsonPath('data.cost_input.known_count', 9)
        ->assertJsonPath('data.cost_input.unknown_count', 0)
        ->assertJsonCount(9, 'data.cost_input.items')
        ->assertJsonPath('data.profit_estimate.status', 'estimated')
        ->assertJsonPath('data.profit_estimate.expected_sale_price_minor', 22000)
        ->assertJsonPath('data.profit_estimate.gross_margin_minor', 7000)
        ->assertJsonPath('data.profit_estimate.total_cost_minor', 19700)
        ->assertJsonPath('data.profit_estimate.expected_net_profit_minor', 2300)
        ->assertJsonPath('data.profit_estimate.profit_margin_basis_points', 1045)
        ->assertJsonPath(
            'data.profit_estimate.return_on_invested_capital_basis_points',
            1168,
        )
        ->assertJsonCount(10, 'data.profit_estimate.items');

    $this->actingAs($owner)
        ->postJson(route('api.v1.analyses.costs.store', $analysis), $payload)
        ->assertOk()
        ->assertJsonPath('meta.created', false);

    $analysis->refresh();
    $estimate = $analysis->currentProfitEstimate()->with('items')->firstOrFail();
    expect(CostInput::query()->count())->toBe(1)
        ->and(ProfitEstimate::query()->count())->toBe(1)
        ->and($estimate->status)->toBe(ProfitEstimateStatus::Estimated)
        ->and($estimate->items)->toHaveCount(10)
        ->and($analysis->result_payload['completed_steps'])
        ->toContain('cost_confirmation')
        ->toContain('profit_calculation')
        ->and($analysis->result_payload['pending_steps'])
        ->not->toContain('profit_calculation')
        ->toContain('deal_score');

    expect(fn () => $estimate->update(['expected_net_profit_minor' => 1]))
        ->toThrow(LogicException::class, 'immutable');
    expect(
        fn () => CostInput::query()
            ->firstOrFail()
            ->update(['known_count' => 0]),
    )->toThrow(LogicException::class, 'immutable');

    $analysis->delete();

    expect(CostInput::query()->count())->toBe(0)
        ->and(ProfitEstimate::query()->count())->toBe(0)
        ->and(ProfitEstimateItem::query()->count())->toBe(0);
});

test('unknown amounts remain explicit and block a falsely precise profit result', function () {
    [$owner, $organization] = profitWorkspace();
    $analysis = profitReadyAnalysis($owner, $organization);
    $payload = profitCostPayload($analysis, [
        'tax_minor' => null,
        'other_costs_minor' => null,
    ]);

    $this->actingAs($owner)
        ->postJson(route('api.v1.analyses.costs.store', $analysis), $payload)
        ->assertCreated()
        ->assertJsonPath('data.status', 'needs_input')
        ->assertJsonPath('data.cost_input.known_count', 7)
        ->assertJsonPath('data.cost_input.unknown_count', 2)
        ->assertJsonPath('data.profit_estimate.status', 'needs_input')
        ->assertJsonPath('data.profit_estimate.total_cost_minor', null)
        ->assertJsonPath('data.profit_estimate.expected_net_profit_minor', null)
        ->assertJsonPath('data.profit_estimate.profit_margin_basis_points', null)
        ->assertJsonPath('data.profit_estimate.unknown_count', 2)
        ->assertJsonFragment([
            'category' => 'tax',
            'amount_minor' => null,
            'is_known' => false,
        ])
        ->assertJsonFragment([
            'category' => 'other_costs',
            'amount_minor' => null,
            'is_known' => false,
        ]);

    expect(
        $analysis->fresh()->result_payload['needs_input'],
    )->toContain('cost_tax_unknown')
        ->toContain('cost_other_costs_unknown')
        ->toContain('profit_estimate_has_unknown_costs');
});

test('cross-border profit remains incomplete until costs and compatibility are confirmed', function () {
    [$owner, $organization] = profitWorkspace();
    $analysis = profitReadyAnalysis($owner, $organization, 'AT');
    $incomplete = profitCostPayload($analysis, [
        'customs_minor' => null,
        'regional_compatibility_confirmed' => null,
    ]);

    $this->actingAs($owner)
        ->postJson(route('api.v1.analyses.costs.store', $analysis), $incomplete)
        ->assertCreated()
        ->assertJsonPath('data.profit_estimate.status', 'needs_input')
        ->assertJsonPath('data.profit_estimate.unknown_count', 2)
        ->assertJsonPath(
            'data.profit_estimate.reason_codes',
            static fn (array $codes): bool => in_array(
                'cross_border_customs_unknown',
                $codes,
                true,
            ) && in_array(
                'regional_compatibility_unconfirmed',
                $codes,
                true,
            ),
        );

    $complete = profitCostPayload($analysis, [
        'customs_minor' => 0,
        'regional_compatibility_confirmed' => true,
    ]);
    $this->actingAs($owner)
        ->postJson(route('api.v1.analyses.costs.store', $analysis), $complete)
        ->assertCreated()
        ->assertJsonPath('data.profit_estimate.run_number', 2)
        ->assertJsonPath('data.profit_estimate.status', 'estimated')
        ->assertJsonPath('data.profit_estimate.unknown_count', 0);

    expect(CostInput::query()->count())->toBe(2)
        ->and(ProfitEstimate::query()->count())->toBe(2)
        ->and($analysis->fresh()->result_payload['needs_input'])
        ->not->toContain('regional_compatibility_unconfirmed');
});

test('negative expected profit is calculated and explained without hiding the loss', function () {
    [$owner, $organization] = profitWorkspace();
    $analysis = profitReadyAnalysis($owner, $organization);
    $payload = profitCostPayload($analysis, [
        'purchase_price_minor' => 25000,
        'transport_minor' => 0,
        'repair_minor' => 0,
        'platform_fees_minor' => 0,
        'payment_fees_minor' => 0,
        'customs_minor' => 0,
        'tax_minor' => 0,
        'other_costs_minor' => 0,
        'safety_reserve_minor' => 0,
    ]);

    $this->actingAs($owner)
        ->postJson(route('api.v1.analyses.costs.store', $analysis), $payload)
        ->assertCreated()
        ->assertJsonPath('data.profit_estimate.gross_margin_minor', -3000)
        ->assertJsonPath('data.profit_estimate.total_cost_minor', 25000)
        ->assertJsonPath('data.profit_estimate.expected_net_profit_minor', -3000)
        ->assertJsonPath('data.profit_estimate.profit_margin_basis_points', -1364)
        ->assertJsonPath(
            'data.profit_estimate.return_on_invested_capital_basis_points',
            -1200,
        )
        ->assertJsonPath(
            'data.profit_estimate.reason_codes',
            static fn (array $codes): bool => in_array(
                'negative_expected_profit',
                $codes,
                true,
            ),
        );
});

test('returning to an older amount after a change appends a new current version', function () {
    [$owner, $organization] = profitWorkspace();
    $analysis = profitReadyAnalysis($owner, $organization);
    $original = profitCostPayload($analysis);
    $changed = profitCostPayload($analysis, ['repair_minor' => 1500]);

    $this->actingAs($owner)
        ->postJson(route('api.v1.analyses.costs.store', $analysis), $original)
        ->assertCreated()
        ->assertJsonPath('data.profit_estimate.run_number', 1);
    $this->actingAs($owner)
        ->postJson(route('api.v1.analyses.costs.store', $analysis), $changed)
        ->assertCreated()
        ->assertJsonPath('data.profit_estimate.run_number', 2);
    $this->actingAs($owner)
        ->postJson(route('api.v1.analyses.costs.store', $analysis), $original)
        ->assertCreated()
        ->assertJsonPath('data.profit_estimate.run_number', 3)
        ->assertJsonPath('data.profit_estimate.expected_net_profit_minor', 2300);
    $this->actingAs($owner)
        ->postJson(route('api.v1.analyses.costs.store', $analysis), $original)
        ->assertOk()
        ->assertJsonPath('meta.created', false)
        ->assertJsonPath('data.profit_estimate.run_number', 3);

    expect(CostInput::query()->count())->toBe(3)
        ->and(ProfitEstimate::query()->count())->toBe(3)
        ->and($analysis->fresh()->currentProfitEstimate()->valueOrFail('run_number'))
        ->toBe(3);
});

test('cost confirmation enforces current evidence currency bounds roles and tenant isolation', function () {
    [$owner, $organization] = profitWorkspace();
    $analysis = profitReadyAnalysis($owner, $organization);
    $payload = profitCostPayload($analysis);
    $viewer = User::factory()->create();
    OrganizationMembership::factory()->create([
        'organization_id' => $organization,
        'user_id' => $viewer,
        'role' => OrganizationRole::Viewer,
    ]);
    $viewer->update(['current_organization_id' => $organization->getKey()]);
    [$outsider] = profitWorkspace();

    $this->postJson(
        route('api.v1.analyses.costs.store', $analysis),
        $payload,
    )->assertUnauthorized();
    $this->actingAs($viewer)
        ->postJson(route('api.v1.analyses.costs.store', $analysis), $payload)
        ->assertForbidden();
    $this->actingAs($outsider)
        ->postJson(route('api.v1.analyses.costs.store', $analysis), $payload)
        ->assertNotFound();
    $this->actingAs($owner)
        ->postJson(
            route('api.v1.analyses.costs.store', $analysis),
            [...$payload, 'currency_code' => 'USD'],
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('currency_code');
    $this->actingAs($owner)
        ->postJson(
            route('api.v1.analyses.costs.store', $analysis),
            [...$payload, 'risk_assessment_id' => str_repeat('0', 26)],
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('analysis');
    $this->actingAs($owner)
        ->postJson(
            route('api.v1.analyses.costs.store', $analysis),
            [
                ...$payload,
                'repair_minor' => (
                    (int) config('profit_calculation.maximum_amount_minor')
                ) + 1,
            ],
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('repair_minor');

    expect(CostInput::query()->count())->toBe(0)
        ->and(ProfitEstimate::query()->count())->toBe(0);
});

test('explicit opportunity evidence produces separate reproducible component scores', function () {
    [$owner, $organization] = profitWorkspace();
    $analysis = opportunityReadyAnalysis($owner, $organization);
    $payload = opportunityPayload($analysis);

    $this->actingAs($owner)
        ->postJson(
            route('api.v1.analyses.opportunity-evidence.store', $analysis),
            $payload,
        )
        ->assertCreated()
        ->assertJsonPath('meta.created', true)
        ->assertJsonPath('data.opportunity_input.run_number', 1)
        ->assertJsonPath('data.opportunity_input.unknown_count', 0)
        ->assertJsonCount(16, 'data.opportunity_input.items')
        ->assertJsonPath('data.logistics_assessment.status', 'assessed')
        ->assertJsonPath('data.logistics_assessment.score', 83)
        ->assertJsonPath('data.logistics_assessment.unknown_count', 0)
        ->assertJsonCount(8, 'data.logistics_assessment.items')
        ->assertJsonPath('data.demand_assessment.status', 'assessed')
        ->assertJsonPath('data.demand_assessment.score', 69)
        ->assertJsonPath('data.demand_assessment.unknown_count', 0)
        ->assertJsonCount(4, 'data.demand_assessment.items')
        ->assertJsonPath('data.deal_score.status', 'assessed')
        ->assertJsonPath('data.deal_score.recommendation', 'needs_verification')
        ->assertJsonPath('data.deal_score.unknown_count', 0)
        ->assertJsonCount(5, 'data.deal_score.items')
        ->assertJsonPath(
            'data.demand_assessment.reason_codes',
            static fn (array $codes): bool => in_array(
                'asking_comparables_do_not_prove_sales',
                $codes,
                true,
            ),
        );

    $this->actingAs($owner)
        ->postJson(
            route('api.v1.analyses.opportunity-evidence.store', $analysis),
            $payload,
        )
        ->assertOk()
        ->assertJsonPath('meta.created', false)
        ->assertJsonPath('data.logistics_assessment.run_number', 1)
        ->assertJsonPath('data.demand_assessment.run_number', 1)
        ->assertJsonPath('data.deal_score.run_number', 1);

    $analysis->refresh();
    $assessments = OpportunityAssessment::query()
        ->with('items')
        ->get()
        ->keyBy(static fn (OpportunityAssessment $assessment): string => (
            $assessment->component->value
        ));
    $dealScore = DealScore::query()->with('items')->firstOrFail();
    expect(OpportunityInput::query()->count())->toBe(1)
        ->and(OpportunityAssessment::query()->count())->toBe(2)
        ->and(OpportunityAssessmentItem::query()->count())->toBe(12)
        ->and(DealScore::query()->count())->toBe(1)
        ->and(DealScoreItem::query()->count())->toBe(5)
        ->and($assessments->get('logistics')->items->sum('maximum_points'))
        ->toBe(100)
        ->and($assessments->get('logistics')->items->sum('score_contribution'))
        ->toBe(83)
        ->and($assessments->get('demand')->items->sum('maximum_points'))
        ->toBe(100)
        ->and($assessments->get('demand')->items->sum('score_contribution'))
        ->toBe(69)
        ->and($dealScore->items->sum('weight_basis_points'))->toBe(10000)
        ->and($dealScore->items->sum(
            'weighted_contribution_basis_points',
        ))->toBe($dealScore->uncapped_score_basis_points)
        ->and($analysis->result_payload['completed_steps'])
        ->toContain('logistics_assessment')
        ->toContain('demand_assessment')
        ->toContain('deal_score')
        ->and($analysis->result_payload['pending_steps'])
        ->not->toContain('deal_score')
        ->not->toContain('logistics_assessment');

    expect(
        fn () => OpportunityInput::query()
            ->firstOrFail()
            ->update(['known_count' => 0]),
    )->toThrow(LogicException::class, 'immutable');
    expect(
        fn () => OpportunityAssessment::query()
            ->firstOrFail()
            ->update(['score' => 100]),
    )->toThrow(LogicException::class, 'immutable');
    expect(
        fn () => DealScore::query()
            ->firstOrFail()
            ->update(['score' => 100]),
    )->toThrow(LogicException::class, 'immutable');

    $this->actingAs($owner)
        ->postJson(
            route('api.v1.analyses.costs.store', $analysis),
            profitCostPayload($analysis, ['repair_minor' => 1200]),
        )
        ->assertCreated()
        ->assertJsonPath('data.opportunity_input', null)
        ->assertJsonPath('data.logistics_assessment', null)
        ->assertJsonPath('data.demand_assessment', null)
        ->assertJsonPath('data.deal_score', null)
        ->assertJsonPath(
            'data.result_payload.pending_steps',
            static fn (array $steps): bool => in_array(
                'logistics_assessment',
                $steps,
                true,
            ) && in_array('demand_assessment', $steps, true)
                && in_array('deal_score', $steps, true),
        );

    $analysis->delete();

    expect(OpportunityInput::query()->count())->toBe(0)
        ->and(OpportunityAssessment::query()->count())->toBe(0)
        ->and(OpportunityAssessmentItem::query()->count())->toBe(0)
        ->and(DealScore::query()->count())->toBe(0)
        ->and(DealScoreItem::query()->count())->toBe(0);
});

test('unknown opportunity facts stay unknown while an observed zero sales count is known', function () {
    [$owner, $organization] = profitWorkspace();
    $analysis = opportunityReadyAnalysis($owner, $organization);
    $unknownPayload = opportunityPayload($analysis, [
        'shipping_method' => null,
        'shipping_distance_km' => null,
        'pickup_available' => null,
        'tracking_available' => null,
        'insurance_available' => null,
        'packaging_confirmed' => null,
        'sold_comparables_count' => null,
        'median_days_to_sale' => null,
        'observation_window_days' => null,
        'demand_evidence_observed_at' => null,
        'demand_evidence_source' => null,
    ]);

    $this->actingAs($owner)
        ->postJson(
            route('api.v1.analyses.opportunity-evidence.store', $analysis),
            $unknownPayload,
        )
        ->assertCreated()
        ->assertJsonPath('data.logistics_assessment.status', 'needs_input')
        ->assertJsonPath('data.logistics_assessment.score', null)
        ->assertJsonPath('data.logistics_assessment.unknown_count', 6)
        ->assertJsonPath('data.demand_assessment.status', 'needs_input')
        ->assertJsonPath('data.demand_assessment.score', null)
        ->assertJsonPath('data.demand_assessment.unknown_count', 4)
        ->assertJsonPath('data.deal_score.status', 'needs_input')
        ->assertJsonPath('data.deal_score.score', null)
        ->assertJsonPath(
            'data.deal_score.recommendation',
            'insufficient_data',
        )
        ->assertJsonPath('data.deal_score.run_number', 1);

    $zeroSalesPayload = opportunityPayload($analysis, [
        'sold_comparables_count' => 0,
        'median_days_to_sale' => null,
    ]);
    $this->actingAs($owner)
        ->postJson(
            route('api.v1.analyses.opportunity-evidence.store', $analysis),
            $zeroSalesPayload,
        )
        ->assertCreated()
        ->assertJsonPath('data.opportunity_input.run_number', 2)
        ->assertJsonPath('data.demand_assessment.run_number', 2)
        ->assertJsonPath('data.demand_assessment.status', 'assessed')
        ->assertJsonPath('data.demand_assessment.score', 30)
        ->assertJsonPath('data.demand_assessment.unknown_count', 0)
        ->assertJsonPath('data.deal_score.status', 'assessed')
        ->assertJsonPath('data.deal_score.run_number', 2)
        ->assertJsonFragment([
            'code' => 'sold_comparables_count',
            'value' => 0,
            'is_known' => true,
        ])
        ->assertJsonFragment([
            'code' => 'observed_sale_velocity',
            'score_contribution' => 0,
            'is_known' => true,
        ]);
});

test('cross border logistics and historical reversion append new assessment runs', function () {
    [$owner, $organization] = profitWorkspace();
    $analysis = opportunityReadyAnalysis($owner, $organization, 'AT');
    $original = opportunityPayload($analysis, [
        'cross_border_handling_confirmed' => true,
    ]);
    $changed = opportunityPayload($analysis, [
        'cross_border_handling_confirmed' => false,
    ]);

    $this->actingAs($owner)
        ->postJson(
            route('api.v1.analyses.opportunity-evidence.store', $analysis),
            $original,
        )
        ->assertCreated()
        ->assertJsonPath('data.logistics_assessment.run_number', 1)
        ->assertJsonPath('data.logistics_assessment.score', 83)
        ->assertJsonPath('data.deal_score.run_number', 1);
    $this->actingAs($owner)
        ->postJson(
            route('api.v1.analyses.opportunity-evidence.store', $analysis),
            $changed,
        )
        ->assertCreated()
        ->assertJsonPath('data.logistics_assessment.run_number', 2)
        ->assertJsonPath('data.logistics_assessment.score', 73)
        ->assertJsonPath('data.deal_score.run_number', 2)
        ->assertJsonPath(
            'data.logistics_assessment.reason_codes',
            static fn (array $codes): bool => in_array(
                'cross_border_handling_not_confirmed',
                $codes,
                true,
            ),
        );
    $this->actingAs($owner)
        ->postJson(
            route('api.v1.analyses.opportunity-evidence.store', $analysis),
            $original,
        )
        ->assertCreated()
        ->assertJsonPath('data.logistics_assessment.run_number', 3)
        ->assertJsonPath('data.logistics_assessment.score', 83)
        ->assertJsonPath('data.deal_score.run_number', 3);
    $this->actingAs($owner)
        ->postJson(
            route('api.v1.analyses.opportunity-evidence.store', $analysis),
            $original,
        )
        ->assertOk()
        ->assertJsonPath('meta.created', false)
        ->assertJsonPath('data.logistics_assessment.run_number', 3)
        ->assertJsonPath('data.deal_score.run_number', 3);

    expect(OpportunityInput::query()->count())->toBe(3)
        ->and(OpportunityAssessment::query()->count())->toBe(6)
        ->and(DealScore::query()->count())->toBe(3);
});

test('opportunity confirmation validates evidence consistency roles bounds and tenancy', function () {
    [$owner, $organization] = profitWorkspace();
    $analysis = opportunityReadyAnalysis($owner, $organization);
    $payload = opportunityPayload($analysis);
    $viewer = User::factory()->create();
    OrganizationMembership::factory()->create([
        'organization_id' => $organization,
        'user_id' => $viewer,
        'role' => OrganizationRole::Viewer,
    ]);
    $viewer->update(['current_organization_id' => $organization->getKey()]);
    [$outsider] = profitWorkspace();

    $this->postJson(
        route('api.v1.analyses.opportunity-evidence.store', $analysis),
        $payload,
    )->assertUnauthorized();
    $this->actingAs($viewer)
        ->postJson(
            route('api.v1.analyses.opportunity-evidence.store', $analysis),
            $payload,
        )
        ->assertForbidden();
    $this->actingAs($outsider)
        ->postJson(
            route('api.v1.analyses.opportunity-evidence.store', $analysis),
            $payload,
        )
        ->assertNotFound();
    $this->actingAs($owner)
        ->postJson(
            route('api.v1.analyses.opportunity-evidence.store', $analysis),
            [
                ...$payload,
                'shipping_method' => 'local_pickup',
                'pickup_available' => false,
            ],
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('pickup_available');
    $this->actingAs($owner)
        ->postJson(
            route('api.v1.analyses.opportunity-evidence.store', $analysis),
            [
                ...$payload,
                'sold_comparables_count' => 2,
                'median_days_to_sale' => null,
            ],
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('median_days_to_sale');
    $this->actingAs($owner)
        ->postJson(
            route('api.v1.analyses.opportunity-evidence.store', $analysis),
            [
                ...$payload,
                'shipping_distance_km' => (
                    (int) config(
                        'opportunity_assessment.maximum_shipping_distance_km',
                    )
                ) + 1,
            ],
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('shipping_distance_km');
    $this->actingAs($owner)
        ->postJson(
            route('api.v1.analyses.opportunity-evidence.store', $analysis),
            [
                ...$payload,
                'profit_estimate_id' => str_repeat('0', 26),
            ],
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('analysis');

    expect(OpportunityInput::query()->count())->toBe(0)
        ->and(OpportunityAssessment::query()->count())->toBe(0)
        ->and(DealScore::query()->count())->toBe(0);
});

test('buyer decisions are append only idempotent and concurrency safe', function () {
    [$owner, $organization] = profitWorkspace();
    $analysis = opportunityReadyAnalysis($owner, $organization);
    $this->actingAs($owner)
        ->postJson(
            route('api.v1.analyses.opportunity-evidence.store', $analysis),
            opportunityPayload($analysis),
        )
        ->assertCreated();
    $dealScoreId = $analysis->fresh()
        ->currentDealScore()
        ->valueOrFail('id');
    $idempotencyKey = (string) Str::uuid();
    $initial = [
        'deal_score_id' => $dealScoreId,
        'expected_current_event_id' => null,
        'next_state' => 'interested',
        'reason_code' => 'margin_reviewed',
        'note' => 'Initial commercial review completed.',
        'idempotency_key' => $idempotencyKey,
    ];

    $created = $this->actingAs($owner)
        ->postJson(
            route('api.v1.analyses.buyer-decisions.store', $analysis),
            $initial,
        )
        ->assertCreated()
        ->assertJsonPath('meta.created', true)
        ->assertJsonPath('data.buyer_decision.sequence', 1)
        ->assertJsonPath('data.buyer_decision.prior_state', null)
        ->assertJsonPath('data.buyer_decision.next_state', 'interested')
        ->assertJsonPath('data.buyer_decision.actor.id', $owner->getKey())
        ->assertJsonPath('data.buyer_decision.deal_score_id', $dealScoreId)
        ->assertJsonPath('data.buyer_decision_history_count', 1)
        ->assertJsonCount(1, 'data.buyer_decision_history')
        ->assertJsonPath(
            'data.buyer_decision_allowed_transitions',
            ['contacted', 'purchased', 'rejected', 'archived'],
        );
    $firstEventId = $created->json('meta.event.id');

    $this->actingAs($owner)
        ->postJson(
            route('api.v1.analyses.buyer-decisions.store', $analysis),
            $initial,
        )
        ->assertOk()
        ->assertJsonPath('meta.created', false)
        ->assertJsonPath('meta.event.id', $firstEventId)
        ->assertJsonPath('data.buyer_decision_history_count', 1);

    $contacted = [
        ...$initial,
        'expected_current_event_id' => $firstEventId,
        'next_state' => 'contacted',
        'reason_code' => 'seller_contacted',
        'note' => null,
        'idempotency_key' => (string) Str::uuid(),
    ];
    $updated = $this->actingAs($owner)
        ->postJson(
            route('api.v1.analyses.buyer-decisions.store', $analysis),
            $contacted,
        )
        ->assertCreated()
        ->assertJsonPath('data.buyer_decision.sequence', 2)
        ->assertJsonPath('data.buyer_decision.previous_event_id', $firstEventId)
        ->assertJsonPath('data.buyer_decision.prior_state', 'interested')
        ->assertJsonPath('data.buyer_decision.next_state', 'contacted')
        ->assertJsonPath('data.buyer_decision_history_count', 2)
        ->assertJsonPath('data.buyer_decision_history.0.sequence', 2)
        ->assertJsonPath(
            'data.buyer_decision_allowed_transitions',
            ['purchased', 'rejected', 'archived'],
        );
    $secondEventId = $updated->json('meta.event.id');

    $this->actingAs($owner)
        ->postJson(
            route('api.v1.analyses.buyer-decisions.store', $analysis),
            [...$initial, 'note' => 'Different command.'],
        )
        ->assertConflict()
        ->assertJsonPath(
            'code',
            'buyer_decision_idempotency_conflict',
        )
        ->assertJsonValidationErrors('idempotency_key');
    $this->actingAs($owner)
        ->postJson(
            route('api.v1.analyses.buyer-decisions.store', $analysis),
            [
                ...$contacted,
                'expected_current_event_id' => $firstEventId,
                'next_state' => 'purchased',
                'idempotency_key' => (string) Str::uuid(),
            ],
        )
        ->assertConflict()
        ->assertJsonPath('code', 'buyer_decision_stale_state')
        ->assertJsonValidationErrors('expected_current_event_id');
    $this->actingAs($owner)
        ->postJson(
            route('api.v1.analyses.buyer-decisions.store', $analysis),
            [
                ...$contacted,
                'expected_current_event_id' => $secondEventId,
                'next_state' => 'interested',
                'idempotency_key' => (string) Str::uuid(),
            ],
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('next_state');

    expect(BuyerDecisionEvent::query()->count())->toBe(2)
        ->and(
            fn () => BuyerDecisionEvent::query()
                ->firstOrFail()
                ->update(['note' => 'Mutated']),
        )
        ->toThrow(LogicException::class, 'immutable');
});

test('a new DealScore resets current buyer decision but preserves history', function () {
    [$owner, $organization] = profitWorkspace();
    $analysis = opportunityReadyAnalysis($owner, $organization);
    $originalOpportunity = opportunityPayload($analysis);
    $this->actingAs($owner)
        ->postJson(
            route('api.v1.analyses.opportunity-evidence.store', $analysis),
            $originalOpportunity,
        )
        ->assertCreated();
    $firstScoreId = $analysis->fresh()
        ->currentDealScore()
        ->valueOrFail('id');
    $this->actingAs($owner)
        ->postJson(
            route('api.v1.analyses.buyer-decisions.store', $analysis),
            [
                'deal_score_id' => $firstScoreId,
                'expected_current_event_id' => null,
                'next_state' => 'rejected',
                'reason_code' => 'risk_too_high',
                'note' => null,
                'idempotency_key' => (string) Str::uuid(),
            ],
        )
        ->assertCreated();

    $newScoreResponse = $this->actingAs($owner)
        ->postJson(
            route('api.v1.analyses.opportunity-evidence.store', $analysis),
            [
                ...$originalOpportunity,
                'tracking_available' => false,
            ],
        )
        ->assertCreated()
        ->assertJsonPath('data.deal_score.run_number', 2)
        ->assertJsonPath('data.buyer_decision', null)
        ->assertJsonPath('data.buyer_decision_history_count', 1)
        ->assertJsonPath(
            'data.buyer_decision_allowed_transitions',
            ['interested', 'contacted', 'purchased', 'rejected', 'archived'],
        );
    $secondScoreId = $newScoreResponse->json('data.deal_score.id');

    $this->actingAs($owner)
        ->postJson(
            route('api.v1.analyses.buyer-decisions.store', $analysis),
            [
                'deal_score_id' => $firstScoreId,
                'expected_current_event_id' => null,
                'next_state' => 'interested',
                'reason_code' => null,
                'note' => null,
                'idempotency_key' => (string) Str::uuid(),
            ],
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('deal_score_id');

    $this->actingAs($owner)
        ->postJson(
            route('api.v1.analyses.buyer-decisions.store', $analysis),
            [
                'deal_score_id' => $secondScoreId,
                'expected_current_event_id' => null,
                'next_state' => 'archived',
                'reason_code' => null,
                'note' => 'Archived against the new assessment.',
                'idempotency_key' => (string) Str::uuid(),
            ],
        )
        ->assertCreated()
        ->assertJsonPath('data.buyer_decision.sequence', 2)
        ->assertJsonPath('data.buyer_decision.previous_event_id', null)
        ->assertJsonPath('data.buyer_decision.deal_score_id', $secondScoreId)
        ->assertJsonPath('data.buyer_decision_history_count', 2)
        ->assertJsonPath('data.buyer_decision_history.1.deal_score_id', $firstScoreId);

    $analysis->delete();

    expect(BuyerDecisionEvent::query()->count())->toBe(0);
});

test('buyer decision endpoint enforces authentication role tenancy and current score', function () {
    [$owner, $organization] = profitWorkspace();
    $analysis = opportunityReadyAnalysis($owner, $organization);
    app(ConfirmOpportunityEvidence::class)->confirm(
        $organization,
        $owner,
        $analysis,
        opportunityPayload($analysis),
    );
    $dealScoreId = $analysis->fresh()
        ->currentDealScore()
        ->valueOrFail('id');
    $payload = [
        'deal_score_id' => $dealScoreId,
        'expected_current_event_id' => null,
        'next_state' => 'interested',
        'reason_code' => null,
        'note' => null,
        'idempotency_key' => (string) Str::uuid(),
    ];
    $viewer = User::factory()->create();
    OrganizationMembership::factory()->create([
        'organization_id' => $organization,
        'user_id' => $viewer,
        'role' => OrganizationRole::Viewer,
    ]);
    $viewer->update(['current_organization_id' => $organization->getKey()]);
    [$outsider] = profitWorkspace();

    $this->postJson(
        route('api.v1.analyses.buyer-decisions.store', $analysis),
        $payload,
    )->assertUnauthorized();
    $this->actingAs($viewer)
        ->postJson(
            route('api.v1.analyses.buyer-decisions.store', $analysis),
            $payload,
        )
        ->assertForbidden();
    $this->actingAs($outsider)
        ->postJson(
            route('api.v1.analyses.buyer-decisions.store', $analysis),
            $payload,
        )
        ->assertNotFound();
    $this->actingAs($owner)
        ->postJson(
            route('api.v1.analyses.buyer-decisions.store', $analysis),
            [...$payload, 'deal_score_id' => str_repeat('0', 26)],
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('deal_score_id');
    $this->actingAs($owner)
        ->postJson(
            route('api.v1.analyses.buyer-decisions.store', $analysis),
            [
                'deal_score_id' => $dealScoreId,
                'next_state' => 'unknown_state',
                'idempotency_key' => 'not-a-uuid',
            ],
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'expected_current_event_id',
            'next_state',
            'idempotency_key',
        ]);

    expect(BuyerDecisionEvent::query()->count())->toBe(0);
});
