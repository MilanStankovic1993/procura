<?php

use App\Actions\Analyses\CreateBuyAnalysisDraft;
use App\Actions\Analyses\RunBuyAnalysis;
use App\Actions\Analyses\SubmitAnalysis;
use App\Actions\Listings\CreateListing;
use App\Actions\Markets\SyncMarketReferenceData;
use App\Analysis\Contracts\ConfiguredListingAiAnalyzer;
use App\Analysis\Contracts\ListingAiAnalyzer;
use App\Analysis\Data\AiAnalysisData;
use App\Analysis\Data\AnalysisInputData;
use App\Analysis\Metrics\AnalysisPipelineStageTimer;
use App\Analysis\Metrics\Contracts\AnalysisPipelineMetricRecorder;
use App\Enums\Analyses\AiAnalysisStatus;
use App\Enums\Analyses\AiValidationStatus;
use App\Enums\Analyses\AnalysisDispatchStatus;
use App\Enums\Analyses\AnalysisPipelineProviderScope;
use App\Enums\Analyses\AnalysisPipelineStage;
use App\Enums\Analyses\AnalysisProviderCircuitState;
use App\Enums\Analyses\AnalysisProviderUsageStatus;
use App\Enums\Analyses\AnalysisStatus;
use App\Enums\Listings\ListingImageKind;
use App\Enums\Organizations\OrganizationRole;
use App\Enums\Subscriptions\FeatureCode;
use App\Exceptions\AnalysisProviderException;
use App\Jobs\Monitoring\MatchListingSnapshot;
use App\Jobs\ProcessBuyAnalysis;
use App\Models\AiAnalysis;
use App\Models\Analysis;
use App\Models\AnalysisDispatch;
use App\Models\AnalysisPipelineMetric;
use App\Models\AnalysisProviderBudgetPeriod;
use App\Models\AnalysisProviderCircuit;
use App\Models\AnalysisProviderUsage;
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
use App\Models\SubscriptionUsage;
use App\Models\User;
use App\Subscriptions\SubscriptionUsageService;
use Database\Seeders\PlanSeeder;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config()->set('performance.analysis_pipeline_metrics.enabled', true);
    app(SyncMarketReferenceData::class)->sync();
    $this->seed(PlanSeeder::class);
    analysisRequestCatalog();
});

function analysisRequestCatalog(): ProductModel
{
    $category = ProductCategory::query()->create([
        'name' => 'Cordless Drills',
        'slug' => 'cordless-drills',
    ]);
    $brand = Brand::query()->create(['name' => 'Bosch Professional']);
    $model = ProductModel::query()->create([
        'brand_id' => $brand->getKey(),
        'product_category_id' => $category->getKey(),
        'name' => 'Bosch Professional Cordless Drill',
        'model_number' => 'QA-DRILL-18V',
        'canonical_key' => 'bosch-professional:qa-drill-18v',
    ]);
    ProductAlias::query()->create([
        'product_model_id' => $model->getKey(),
        'alias' => 'Bosch Professional cordless drill',
        'source' => 'golden_test',
    ]);

    return $model;
}

function analysisRequestWorkspace(
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

function analysisRequestListing(
    User $user,
    Organization $organization,
    bool $withEvidence = false,
    array $overrides = [],
): Listing {
    $listing = app(CreateListing::class)->create(
        $organization,
        $user,
        MarketplaceSource::query()->where('key', 'manual')->firstOrFail(),
        [
            'source_url' => 'https://market.example/listings/analysis-drill',
            'external_id' => 'analysis-drill',
            'marketplace_name' => 'Analysis Market',
            'title' => 'Bosch Professional cordless drill',
            'description' => 'Two batteries, charger, and case.',
            'asking_price_minor' => 12999,
            'currency_code' => 'EUR',
            'seller_information' => 'Private seller.',
            'location' => 'Vienna',
            'source_country_code' => 'AT',
            'target_country_code' => 'DE',
            'status' => 'active',
            'notes' => null,
            ...$overrides,
        ],
    );

    if ($withEvidence) {
        ListingImage::query()->create([
            'listing_id' => $listing->getKey(),
            'uploaded_by_user_id' => $user->getKey(),
            'kind' => ListingImageKind::Product,
            'disk' => 'local',
            'path' => "testing/{$listing->getKey()}/drill.png",
            'client_filename' => 'drill.png',
            'mime_type' => 'image/png',
            'extension' => 'png',
            'size_bytes' => 2048,
            'width' => 800,
            'height' => 600,
            'checksum_sha256' => str_repeat('a', 64),
            'position' => 1,
        ]);
    }

    return $listing;
}

function analysisRequestSubmittedAnalysis(
    User $user,
    Organization $organization,
    string $suffix,
): Analysis {
    $listing = analysisRequestListing(
        $user,
        $organization,
        withEvidence: true,
        overrides: [
            'source_url' => "https://market.example/listings/{$suffix}",
            'external_id' => $suffix,
        ],
    );
    $analysis = app(CreateBuyAnalysisDraft::class)->create(
        $organization,
        $user,
        $listing->getKey(),
        'DE',
    );

    return app(SubmitAnalysis::class)->submit(
        $organization,
        $user,
        $analysis->getKey(),
    );
}

function analysisRequestExternalResult(int $costMinor = 2): AiAnalysisData
{
    return new AiAnalysisData(
        normalizedListing: [
            'title' => 'Bosch Professional cordless drill',
            'description' => 'Two batteries, charger, and case.',
            'marketplace_name' => 'Analysis Market',
            'asking_price_minor' => 12999,
            'currency_code' => 'EUR',
            'source_country_code' => 'AT',
            'target_country_code' => 'DE',
            'evidence_count' => 1,
        ],
        confidenceBasisPoints: 9100,
        tokensIn: 1234,
        tokensOut: 321,
        estimatedCostMinor: $costMinor,
        estimatedCostCurrency: 'USD',
    );
}

test('an analyst creates an immutable draft from an exact listing snapshot without using quota', function () {
    Queue::fake();
    [$analyst, $organization] = analysisRequestWorkspace(OrganizationRole::Analyst);
    $listing = analysisRequestListing($analyst, $organization, withEvidence: true);

    $response = $this->actingAs($analyst)
        ->postJson(route('api.v1.buy-analyses.store'), [
            'listing_id' => $listing->getKey(),
            'target_country_code' => 'FR',
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.source_country_code', 'AT')
        ->assertJsonPath('data.target_country_code', 'FR')
        ->assertJsonPath('data.request_payload.market_scope.target_country_code', 'FR')
        ->assertJsonPath('data.request_payload.listing.snapshot_sequence', 1)
        ->assertJsonPath('data.request_payload.evidence.0.checksum_sha256', str_repeat('a', 64));

    $analysis = Analysis::query()->findOrFail($response->json('data.id'));
    $duplicate = app(CreateBuyAnalysisDraft::class)->create(
        $organization,
        $analyst,
        $listing->getKey(),
        'FR',
    );

    expect($analysis->request_hash)->toHaveLength(64)
        ->and($duplicate->is($analysis))->toBeTrue()
        ->and(Analysis::query()->count())->toBe(1)
        ->and($analysis->listing_snapshot_id)->toBe($listing->snapshots()->value('id'))
        ->and(SubscriptionUsage::query()->count())->toBe(0)
        ->and(AnalysisDispatch::query()->count())->toBe(0);
    Queue::assertPushed(MatchListingSnapshot::class, 1);
    Queue::assertNotPushed(ProcessBuyAnalysis::class);

    expect(fn () => $analysis->update(['target_country_code' => 'IT']))
        ->toThrow(LogicException::class);
});

test('submitting a draft consumes quota and dispatches exactly once across duplicate requests', function () {
    Queue::fake();
    [$analyst, $organization] = analysisRequestWorkspace(OrganizationRole::Analyst);
    $listing = analysisRequestListing($analyst, $organization, withEvidence: true);
    $analysis = app(CreateBuyAnalysisDraft::class)->create(
        $organization,
        $analyst,
        $listing->getKey(),
        'DE',
    );

    $this->actingAs($analyst)
        ->postJson(route('api.v1.analyses.submit', $analysis))
        ->assertAccepted()
        ->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.current_dispatch.status', 'dispatched');
    $this->actingAs($analyst)
        ->postJson(route('api.v1.analyses.submit', $analysis))
        ->assertAccepted();

    expect(SubscriptionUsage::query()->value('used'))->toBe(1)
        ->and(DB::table('subscription_usage_events')->count())->toBe(1)
        ->and(AnalysisDispatch::query()->count())->toBe(1)
        ->and(AnalysisDispatch::query()->value('dispatch_attempts'))->toBe(1);
    Queue::assertPushed(ProcessBuyAnalysis::class, 1);
});

test('the production kill switch leaves a draft and its quota untouched', function () {
    Queue::fake();
    config()->set('analyses.submission_enabled', false);
    [$analyst, $organization] = analysisRequestWorkspace(
        OrganizationRole::Analyst,
    );
    $listing = analysisRequestListing(
        $analyst,
        $organization,
        withEvidence: true,
    );
    $analysis = app(CreateBuyAnalysisDraft::class)->create(
        $organization,
        $analyst,
        $listing->getKey(),
        'DE',
    );

    $this->actingAs($analyst)
        ->postJson(route('api.v1.analyses.submit', $analysis))
        ->assertUnprocessable()
        ->assertJsonPath(
            'errors.analysis.0',
            __('application_validation.analysis_submission_disabled'),
        );

    expect($analysis->fresh()->status)->toBe(AnalysisStatus::Draft)
        ->and(SubscriptionUsage::query()->count())->toBe(0)
        ->and(AnalysisDispatch::query()->count())->toBe(0);
    Queue::assertPushed(MatchListingSnapshot::class, 1);
    Queue::assertNotPushed(ProcessBuyAnalysis::class);
});

test('quota exhaustion leaves the analysis as a draft with no dispatch record', function () {
    Queue::fake();
    [$owner, $organization] = analysisRequestWorkspace();
    $listing = analysisRequestListing($owner, $organization);
    $usage = app(SubscriptionUsageService::class);

    for ($index = 1; $index <= 5; $index++) {
        $usage->consume(
            $organization,
            FeatureCode::MonthlyAnalyses,
            1,
            "preexisting-analysis:{$index}",
        );
    }

    $analysis = app(CreateBuyAnalysisDraft::class)->create(
        $organization,
        $owner,
        $listing->getKey(),
        'DE',
    );

    $this->actingAs($owner)
        ->postJson(route('api.v1.analyses.submit', $analysis))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'analysis_quota_exceeded')
        ->assertJsonPath('meta.limit', 5)
        ->assertJsonPath('meta.used', 5);

    expect($analysis->fresh()->status)->toBe(AnalysisStatus::Draft)
        ->and(AnalysisDispatch::query()->count())->toBe(0)
        ->and(SubscriptionUsage::query()->value('used'))->toBe(5);
    Queue::assertPushed(MatchListingSnapshot::class, 1);
    Queue::assertNotPushed(ProcessBuyAnalysis::class);
});

test('analysis routes never reveal or mutate another tenant records', function () {
    Queue::fake();
    [$owner, $organization] = analysisRequestWorkspace();
    [$outsider] = analysisRequestWorkspace();
    $listing = analysisRequestListing($owner, $organization);
    $analysis = app(CreateBuyAnalysisDraft::class)->create(
        $organization,
        $owner,
        $listing->getKey(),
        'DE',
    );

    $this->actingAs($outsider)
        ->postJson(route('api.v1.buy-analyses.store'), [
            'listing_id' => $listing->getKey(),
            'target_country_code' => 'DE',
        ])
        ->assertNotFound();
    $this->actingAs($outsider)
        ->getJson(route('api.v1.analyses.show', $analysis))
        ->assertNotFound();
    $this->actingAs($outsider)
        ->postJson(route('api.v1.analyses.submit', $analysis))
        ->assertNotFound();
    $this->actingAs($outsider)
        ->getJson(route('api.v1.analyses.index', ['listing_id' => $listing->getKey()]))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('viewers can inspect analyses while only analysts and managers can create or submit', function () {
    Queue::fake();
    [$owner, $organization] = analysisRequestWorkspace();
    $viewer = User::factory()->create();
    OrganizationMembership::factory()->viewer()->create([
        'organization_id' => $organization,
        'user_id' => $viewer,
    ]);
    $viewer->update(['current_organization_id' => $organization->getKey()]);
    $listing = analysisRequestListing($owner, $organization);
    $analysis = app(CreateBuyAnalysisDraft::class)->create(
        $organization,
        $owner,
        $listing->getKey(),
        'DE',
    );

    $this->actingAs($viewer)
        ->getJson(route('api.v1.analyses.show', $analysis))
        ->assertOk();
    $this->actingAs($viewer)
        ->postJson(route('api.v1.buy-analyses.store'), [
            'listing_id' => $listing->getKey(),
            'target_country_code' => 'DE',
        ])
        ->assertForbidden();
    $this->actingAs($viewer)
        ->postJson(route('api.v1.analyses.submit', $analysis))
        ->assertForbidden();
});

test('the deterministic provider preserves a missing-comparables boundary and duplicate jobs are inert', function () {
    Queue::fake();
    [$analyst, $organization] = analysisRequestWorkspace(OrganizationRole::Analyst);
    $listing = analysisRequestListing($analyst, $organization, withEvidence: true);
    $analysis = app(CreateBuyAnalysisDraft::class)->create(
        $organization,
        $analyst,
        $listing->getKey(),
        'DE',
    );
    $analysis = app(SubmitAnalysis::class)->submit($organization, $analyst, $analysis->getKey());
    $dispatch = $analysis->currentDispatch()->firstOrFail();

    app(RunBuyAnalysis::class)->run($analysis->getKey(), $dispatch->getKey());
    app(RunBuyAnalysis::class)->run($analysis->getKey(), $dispatch->getKey());

    $analysis->refresh();
    $attempt = AiAnalysis::query()->firstOrFail();
    $metric = AnalysisPipelineMetric::query()->firstOrFail();

    expect($analysis->status)->toBe(AnalysisStatus::NeedsInput)
        ->and($analysis->processing_attempts)->toBe(1)
        ->and($analysis->result_payload['normalized_listing']['asking_price_minor'])->toBe(12999)
        ->and($analysis->result_payload['completed_steps'])->toContain('product_matching')
        ->and($analysis->result_payload['completed_steps'])->toContain('comparable_selection')
        ->and(in_array('product_matching', $analysis->result_payload['pending_steps'], true))
        ->toBeFalse()
        ->and(in_array('comparable_selection', $analysis->result_payload['pending_steps'], true))
        ->toBeFalse()
        ->and($analysis->result_payload['needs_input'])->toContain('no_comparable_records')
        ->and($analysis->result_payload['pending_steps'])->toContain('price_estimation')
        ->and($dispatch->fresh()->status)->toBe(AnalysisDispatchStatus::Completed)
        ->and($attempt->status)->toBe(AiAnalysisStatus::Completed)
        ->and($attempt->validation_status)->toBe(AiValidationStatus::Valid)
        ->and($attempt->confidence_basis_points)->toBe(9000)
        ->and(AiAnalysis::query()->count())->toBe(1)
        ->and(ProductMatch::query()->count())->toBe(1)
        ->and(ProductMatch::query()->firstOrFail()->status->value)->toBe('matched')
        ->and(AnalysisPipelineMetric::query()->count())->toBe(1)
        ->and($metric->ai_analysis_id)->toBe($attempt->getKey())
        ->and($metric->provider_scope)->toBe(AnalysisPipelineProviderScope::Rehearsal)
        ->and($metric->attempt_status)->toBe(AiAnalysisStatus::Completed)
        ->and($metric->failed_stage)->toBeNull()
        ->and($metric->provider_analysis_microseconds)->toBeGreaterThan(0)
        ->and($metric->product_matching_microseconds)->toBeGreaterThan(0)
        ->and($metric->comparable_selection_microseconds)->toBeGreaterThan(0)
        ->and($metric->price_estimation_microseconds)->toBeNull()
        ->and($metric->risk_assessment_microseconds)->toBeNull()
        ->and($metric->finalization_microseconds)->toBeGreaterThan(0)
        ->and($metric->total_microseconds)->toBeGreaterThanOrEqual(
            $metric->provider_analysis_microseconds
            + $metric->product_matching_microseconds
            + $metric->comparable_selection_microseconds
            + $metric->finalization_microseconds,
        );
});

test('missing price currency and evidence produce a structured needs input state', function () {
    Queue::fake();
    [$owner, $organization] = analysisRequestWorkspace();
    $listing = analysisRequestListing($owner, $organization, overrides: [
        'asking_price_minor' => null,
        'currency_code' => null,
    ]);
    $analysis = app(CreateBuyAnalysisDraft::class)->create(
        $organization,
        $owner,
        $listing->getKey(),
        'DE',
    );
    $analysis = app(SubmitAnalysis::class)->submit($organization, $owner, $analysis->getKey());

    app(RunBuyAnalysis::class)->run(
        $analysis->getKey(),
        $analysis->currentDispatch()->valueOrFail('id'),
    );

    $analysis->refresh();
    expect($analysis->status)->toBe(AnalysisStatus::NeedsInput)
        ->and($analysis->result_payload['needs_input'])
        ->toBe([
            'price_missing',
            'currency_unclear',
            'images_insufficient',
            'no_comparable_records',
        ]);
});

test('a configured provider records its model token usage and estimated cost', function () {
    Queue::fake();
    [$owner, $organization] = analysisRequestWorkspace();
    $listing = analysisRequestListing($owner, $organization, withEvidence: true);
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
    app()->instance(
        ListingAiAnalyzer::class,
        new class implements ConfiguredListingAiAnalyzer
        {
            public function isConfigured(): bool
            {
                return true;
            }

            public function model(): string
            {
                return 'production-model-v1';
            }

            public function provider(): string
            {
                return 'production-provider';
            }

            public function maximumCostMinor(AnalysisInputData $input): int
            {
                return 5;
            }

            public function analyze(AnalysisInputData $input): AiAnalysisData
            {
                return new AiAnalysisData(
                    normalizedListing: [
                        'title' => 'Bosch Professional cordless drill',
                        'description' => 'Two batteries, charger, and case.',
                        'marketplace_name' => 'Analysis Market',
                        'asking_price_minor' => 12999,
                        'currency_code' => 'EUR',
                        'source_country_code' => 'AT',
                        'target_country_code' => 'DE',
                        'evidence_count' => 1,
                    ],
                    confidenceBasisPoints: 9100,
                    tokensIn: 1234,
                    tokensOut: 321,
                    estimatedCostMinor: 2,
                    estimatedCostCurrency: 'USD',
                );
            }
        },
    );

    app(RunBuyAnalysis::class)->run(
        $analysis->getKey(),
        $analysis->currentDispatch()->valueOrFail('id'),
    );

    $attempt = AiAnalysis::query()->firstOrFail();
    $usage = AnalysisProviderUsage::query()->firstOrFail();
    $circuit = AnalysisProviderCircuit::query()->firstOrFail();

    expect($attempt->model)->toBe('production-model-v1')
        ->and($attempt->tokens_in)->toBe(1234)
        ->and($attempt->tokens_out)->toBe(321)
        ->and($attempt->estimated_cost_minor)->toBe(2)
        ->and($attempt->estimated_cost_currency)->toBe('USD')
        ->and($analysis->fresh()->result_payload['completed_steps'])
        ->toContain('ai_extraction')
        ->and($usage->status)->toBe(AnalysisProviderUsageStatus::Completed)
        ->and($usage->reserved_cost_minor)->toBe(5)
        ->and($usage->actual_cost_minor)->toBe(2)
        ->and(AnalysisProviderBudgetPeriod::query()->count())->toBe(3)
        ->and(AnalysisProviderBudgetPeriod::query()->sum('reserved_cost_minor'))->toBe(0)
        ->and(AnalysisProviderBudgetPeriod::query()->sum('consumed_cost_minor'))->toBe(6)
        ->and($circuit->state)->toBe(AnalysisProviderCircuitState::Closed)
        ->and($circuit->consecutive_failures)->toBe(0);
});

test('organization AI cost budgets span providers and block a second call without consuming a retry', function () {
    Queue::fake();
    config()->set('analyses.provider_governance.task_max_cost_minor', 5);
    config()->set('analyses.provider_governance.global_monthly_budget_minor', 100);
    config()->set('analyses.provider_governance.organization_monthly_budget_minor', 6);
    config()->set('analyses.provider_governance.user_monthly_budget_minor', 6);
    [$owner, $organization] = analysisRequestWorkspace();
    $provider = new class implements ConfiguredListingAiAnalyzer
    {
        public int $calls = 0;

        public string $providerName = 'budget-provider';

        public string $modelName = 'budget-model-v1';

        public function isConfigured(): bool
        {
            return true;
        }

        public function provider(): string
        {
            return $this->providerName;
        }

        public function model(): string
        {
            return $this->modelName;
        }

        public function maximumCostMinor(AnalysisInputData $input): int
        {
            return 5;
        }

        public function analyze(AnalysisInputData $input): AiAnalysisData
        {
            $this->calls++;

            return analysisRequestExternalResult();
        }
    };
    app()->instance(ListingAiAnalyzer::class, $provider);
    $runner = app(RunBuyAnalysis::class);
    $first = analysisRequestSubmittedAnalysis($owner, $organization, 'budget-first');

    $runner->run(
        $first->getKey(),
        $first->currentDispatch()->valueOrFail('id'),
    );

    $provider->providerName = 'alternate-budget-provider';
    $provider->modelName = 'alternate-budget-model-v1';
    $second = analysisRequestSubmittedAnalysis($owner, $organization, 'budget-second');

    expect(fn () => $runner->run(
        $second->getKey(),
        $second->currentDispatch()->valueOrFail('id'),
    ))->toThrow(AnalysisProviderException::class);

    expect($provider->calls)->toBe(1)
        ->and(AnalysisProviderUsage::query()->count())->toBe(1)
        ->and($second->fresh()->last_error_code)
        ->toBe('analysis_provider_organization_budget_exhausted')
        ->and($second->fresh()->next_retry_at)->toBeNull();
});

test('the provider circuit opens after bounded failures and one successful probe closes it', function () {
    Queue::fake();
    config()->set('analyses.provider_governance.circuit_failure_threshold', 2);
    config()->set('analyses.provider_governance.circuit_cooldown_seconds', 300);
    [$owner, $organization] = analysisRequestWorkspace();
    $provider = new class implements ConfiguredListingAiAnalyzer
    {
        public int $calls = 0;

        public bool $fails = true;

        public function isConfigured(): bool
        {
            return true;
        }

        public function provider(): string
        {
            return 'circuit-provider';
        }

        public function model(): string
        {
            return 'circuit-model-v1';
        }

        public function maximumCostMinor(AnalysisInputData $input): int
        {
            return 5;
        }

        public function analyze(AnalysisInputData $input): AiAnalysisData
        {
            $this->calls++;

            if ($this->fails) {
                throw new AnalysisProviderException(
                    'analysis_provider_transport_failed',
                );
            }

            return analysisRequestExternalResult();
        }
    };
    app()->instance(ListingAiAnalyzer::class, $provider);
    $runner = app(RunBuyAnalysis::class);

    foreach (['circuit-first', 'circuit-second'] as $suffix) {
        $analysis = analysisRequestSubmittedAnalysis($owner, $organization, $suffix);

        expect(fn () => $runner->run(
            $analysis->getKey(),
            $analysis->currentDispatch()->valueOrFail('id'),
        ))->toThrow(AnalysisProviderException::class);
    }

    $blocked = analysisRequestSubmittedAnalysis($owner, $organization, 'circuit-blocked');

    expect(fn () => $runner->run(
        $blocked->getKey(),
        $blocked->currentDispatch()->valueOrFail('id'),
    ))->toThrow(AnalysisProviderException::class);

    $circuit = AnalysisProviderCircuit::query()->firstOrFail();
    expect($provider->calls)->toBe(2)
        ->and(AnalysisProviderUsage::query()
            ->where('status', AnalysisProviderUsageStatus::Uncertain)
            ->count())->toBe(2)
        ->and($circuit->state)->toBe(AnalysisProviderCircuitState::Open)
        ->and($circuit->consecutive_failures)->toBe(2)
        ->and($blocked->fresh()->last_error_code)
        ->toBe('analysis_provider_circuit_open')
        ->and($blocked->fresh()->next_retry_at)->toBeNull();

    $provider->fails = false;
    $this->travel(301)->seconds();
    $probe = analysisRequestSubmittedAnalysis($owner, $organization, 'circuit-probe');
    $runner->run(
        $probe->getKey(),
        $probe->currentDispatch()->valueOrFail('id'),
    );

    $circuit->refresh();
    expect($provider->calls)->toBe(3)
        ->and($circuit->state)->toBe(AnalysisProviderCircuitState::Closed)
        ->and($circuit->consecutive_failures)->toBe(0)
        ->and($circuit->probe_ai_analysis_id)->toBeNull();
});

test('provider failures record bounded retry metadata and one observable attempt per run', function () {
    Queue::fake();
    [$owner, $organization] = analysisRequestWorkspace();
    $listing = analysisRequestListing($owner, $organization, withEvidence: true);
    $analysis = app(CreateBuyAnalysisDraft::class)->create(
        $organization,
        $owner,
        $listing->getKey(),
        'DE',
    );
    $analysis = app(SubmitAnalysis::class)->submit($organization, $owner, $analysis->getKey());
    $dispatch = $analysis->currentDispatch()->firstOrFail();
    app()->instance(ListingAiAnalyzer::class, new class implements ListingAiAnalyzer
    {
        public function analyze(AnalysisInputData $input): AiAnalysisData
        {
            throw new AnalysisProviderException(
                'analysis_provider_transport_failed',
            );
        }
    });
    $runner = app(RunBuyAnalysis::class);

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        expect(fn () => $runner->run($analysis->getKey(), $dispatch->getKey()))
            ->toThrow(
                AnalysisProviderException::class,
                'The analysis provider request failed.',
            );
    }

    $runner->run($analysis->getKey(), $dispatch->getKey());
    $analysis->refresh();

    expect($analysis->status)->toBe(AnalysisStatus::Failed)
        ->and($analysis->processing_attempts)->toBe(3)
        ->and($analysis->next_retry_at)->toBeNull()
        ->and($analysis->last_error_code)->toBe(
            'analysis_provider_transport_failed',
        )
        ->and($dispatch->fresh()->status)->toBe(AnalysisDispatchStatus::Failed)
        ->and(AiAnalysis::query()->count())->toBe(3)
        ->and(AiAnalysis::query()->where('status', AiAnalysisStatus::Failed)->count())->toBe(3)
        ->and(AnalysisPipelineMetric::query()->count())->toBe(3)
        ->and(AnalysisPipelineMetric::query()
            ->where('failed_stage', AnalysisPipelineStage::ProviderAnalysis)
            ->count())->toBe(3)
        ->and(AnalysisPipelineMetric::query()
            ->whereNotNull('finalization_microseconds')
            ->count())->toBe(3);
});

test('a metric recorder outage cannot corrupt a completed analysis', function () {
    Queue::fake();
    [$owner, $organization] = analysisRequestWorkspace();
    $listing = analysisRequestListing($owner, $organization, withEvidence: true);
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
    app()->instance(
        AnalysisPipelineMetricRecorder::class,
        new class implements AnalysisPipelineMetricRecorder
        {
            public function record(
                string $aiAnalysisId,
                int $attemptNumber,
                string $pipelineVersion,
                AnalysisPipelineProviderScope $providerScope,
                AnalysisPipelineStageTimer $timer,
            ): void {
                throw new RuntimeException('Simulated metric storage outage.');
            }
        },
    );

    app(RunBuyAnalysis::class)->run(
        $analysis->getKey(),
        $analysis->currentDispatch()->valueOrFail('id'),
    );

    expect($analysis->fresh()->status)->toBe(AnalysisStatus::NeedsInput)
        ->and(AiAnalysis::query()->firstOrFail()->status)
        ->toBe(AiAnalysisStatus::Completed)
        ->and(AnalysisPipelineMetric::query()->count())->toBe(0);
});

test('a metric recorder outage preserves the original provider failure', function () {
    Queue::fake();
    [$owner, $organization] = analysisRequestWorkspace();
    $listing = analysisRequestListing($owner, $organization, withEvidence: true);
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
    app()->instance(ListingAiAnalyzer::class, new class implements ListingAiAnalyzer
    {
        public function analyze(AnalysisInputData $input): AiAnalysisData
        {
            throw new RuntimeException('Original provider failure.');
        }
    });
    app()->instance(
        AnalysisPipelineMetricRecorder::class,
        new class implements AnalysisPipelineMetricRecorder
        {
            public function record(
                string $aiAnalysisId,
                int $attemptNumber,
                string $pipelineVersion,
                AnalysisPipelineProviderScope $providerScope,
                AnalysisPipelineStageTimer $timer,
            ): void {
                throw new RuntimeException('Simulated metric storage outage.');
            }
        },
    );

    expect(fn () => app(RunBuyAnalysis::class)->run(
        $analysis->getKey(),
        $analysis->currentDispatch()->valueOrFail('id'),
    ))->toThrow(RuntimeException::class, 'Original provider failure.');

    expect($analysis->fresh()->status)->toBe(AnalysisStatus::Failed)
        ->and(AiAnalysis::query()->firstOrFail()->status)
        ->toBe(AiAnalysisStatus::Failed)
        ->and(AnalysisPipelineMetric::query()->count())->toBe(0);
});

test('an exhausted queue job records an unexpected terminal failure without corrupting completed work', function () {
    Queue::fake();
    [$owner, $organization] = analysisRequestWorkspace();
    $listing = analysisRequestListing($owner, $organization, withEvidence: true);
    $analysis = app(CreateBuyAnalysisDraft::class)->create(
        $organization,
        $owner,
        $listing->getKey(),
        'DE',
    );
    $analysis = app(SubmitAnalysis::class)->submit($organization, $owner, $analysis->getKey());
    $dispatch = $analysis->currentDispatch()->firstOrFail();
    $job = new ProcessBuyAnalysis($analysis->getKey(), $dispatch->getKey());

    $job->failed(new RuntimeException('Queue worker exhausted all attempts.'));
    $analysis->refresh();
    $dispatch->refresh();

    expect($analysis->status)->toBe(AnalysisStatus::Failed)
        ->and($analysis->next_retry_at)->toBeNull()
        ->and($analysis->last_error_code)->toBe('RuntimeException')
        ->and($dispatch->status)->toBe(AnalysisDispatchStatus::Failed)
        ->and($dispatch->available_at)->toBeNull()
        ->and(AiAnalysis::query()->count())->toBe(0);

    $analysis->update([
        'status' => AnalysisStatus::Completed,
        'finished_at' => now(),
        'last_error_code' => null,
        'last_error_message' => null,
    ]);
    $dispatch->update([
        'status' => AnalysisDispatchStatus::Completed,
        'completed_at' => now(),
    ]);

    $job->failed(new RuntimeException('Late duplicate failure callback.'));
    $analysis->refresh();
    $dispatch->refresh();

    expect($analysis->status)->toBe(AnalysisStatus::Completed)
        ->and($analysis->last_error_code)->toBeNull()
        ->and($dispatch->status)->toBe(AnalysisDispatchStatus::Completed);
});

test('the recovery command redispatches a stale processing lease and preserves attempt history', function () {
    Queue::fake();
    [$owner, $organization] = analysisRequestWorkspace();
    $listing = analysisRequestListing($owner, $organization, withEvidence: true);
    $analysis = app(CreateBuyAnalysisDraft::class)->create(
        $organization,
        $owner,
        $listing->getKey(),
        'DE',
    );
    $analysis = app(SubmitAnalysis::class)->submit($organization, $owner, $analysis->getKey());
    $dispatch = $analysis->currentDispatch()->firstOrFail();
    $staleStartedAt = now()->subSeconds(
        (int) config('analyses.processing_timeout_seconds') + 1,
    );
    $analysis->update([
        'status' => AnalysisStatus::Processing,
        'processing_attempts' => 1,
        'processing_started_at' => $staleStartedAt,
    ]);
    $dispatch->update(['status' => AnalysisDispatchStatus::Processing]);
    $abandonedAttempt = AiAnalysis::query()->create([
        'organization_id' => $organization->getKey(),
        'analysis_id' => $analysis->getKey(),
        'attempt_number' => 1,
        'status' => AiAnalysisStatus::Processing,
        'provider' => config('analyses.provider'),
        'model' => config('analyses.fake_model'),
        'prompt_version' => config('analyses.prompt_version'),
        'input_hash' => $analysis->request_hash,
        'input_snapshot' => $analysis->request_payload,
        'validation_status' => AiValidationStatus::Pending,
        'started_at' => $staleStartedAt,
    ]);
    app(UniqueLock::class)->release(
        new ProcessBuyAnalysis($analysis->getKey(), $dispatch->getKey()),
    );

    $this->artisan('analyses:dispatch-pending')
        ->expectsOutput('Processed 1 pending analysis dispatch records.')
        ->assertSuccessful();

    Queue::assertPushed(ProcessBuyAnalysis::class, 2);
    $dispatch->refresh();
    expect($dispatch->status)->toBe(AnalysisDispatchStatus::Dispatched)
        ->and($dispatch->dispatch_attempts)->toBe(2);

    app(RunBuyAnalysis::class)->run($analysis->getKey(), $dispatch->getKey());
    $analysis->refresh();
    $abandonedAttempt->refresh();

    expect($analysis->status)->toBe(AnalysisStatus::NeedsInput)
        ->and($analysis->processing_attempts)->toBe(2)
        ->and($abandonedAttempt->status)->toBe(AiAnalysisStatus::Failed)
        ->and($abandonedAttempt->validation_status)->toBe(AiValidationStatus::Invalid)
        ->and($abandonedAttempt->error)
        ->toBe('The previous processing lease expired before completion.')
        ->and(AiAnalysis::query()->count())->toBe(2);
});
