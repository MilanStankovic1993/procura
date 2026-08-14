<?php

use App\Actions\Administration\AssignOrganizationPlan;
use App\Actions\Administration\BootstrapSuperAdmin;
use App\Actions\BrokerRequests\AcceptBrokerRequestOffer;
use App\Actions\BrokerRequests\CreateBrokerRequest;
use App\Actions\BrokerRequests\OpenBrokerPaymentCase;
use App\Actions\BrokerRequests\PresentBrokerRequestOffer;
use App\Actions\BrokerRequests\TransitionBrokerRequest;
use App\Actions\BrokerRequests\TransitionBrokerTransaction;
use App\Actions\Markets\SyncMarketReferenceData;
use App\Actions\Privacy\CreatePrivacyRequest;
use App\Enums\Analyses\AiAnalysisStatus;
use App\Enums\Analyses\AiValidationStatus;
use App\Enums\Analyses\AnalysisStatus;
use App\Enums\Analyses\AnalysisType;
use App\Enums\BrokerRequests\BrokerPaymentCaseType;
use App\Enums\BrokerRequests\BrokerRequestStatus;
use App\Enums\BrokerRequests\BrokerTransactionStatus;
use App\Enums\Catalog\ProductMatchReviewStatus;
use App\Enums\Catalog\ProductMatchStatus;
use App\Enums\Comparables\ComparableSetStatus;
use App\Enums\Localization\SupportedLocale;
use App\Enums\Pricing\PriceConfidenceLevel;
use App\Enums\Pricing\PriceEstimateStatus;
use App\Enums\Risk\RiskAssessmentStatus;
use App\Enums\Risk\RiskConfidenceLevel;
use App\Enums\Risk\RiskLevel;
use App\Filament\Resources\AiAnalyses\AiAnalysisResource;
use App\Filament\Resources\Analyses\AnalysisResource;
use App\Filament\Resources\AnalysisOperations\AnalysisOperationResource;
use App\Filament\Resources\AuditEvents\AuditEventResource;
use App\Filament\Resources\BillingProviderEvents\BillingProviderEventResource;
use App\Filament\Resources\Brands\BrandResource;
use App\Filament\Resources\BrokerCommissionResource;
use App\Filament\Resources\BrokerPaymentCaseResource;
use App\Filament\Resources\BrokerReportResource;
use App\Filament\Resources\BrokerRequestOfferResource;
use App\Filament\Resources\BrokerRequestResource;
use App\Filament\Resources\BrokerTransactionResource;
use App\Filament\Resources\ComparableMarketNormalizations\ComparableMarketNormalizationResource;
use App\Filament\Resources\Countries\CountryResource;
use App\Filament\Resources\Currencies\CurrencyResource;
use App\Filament\Resources\Listings\ListingResource;
use App\Filament\Resources\MarketplaceSources\MarketplaceSourceResource;
use App\Filament\Resources\Memberships\MembershipResource;
use App\Filament\Resources\NotificationDeliveries\NotificationDeliveryResource;
use App\Filament\Resources\Organizations\OrganizationResource;
use App\Filament\Resources\PlanFeatures\PlanFeatureResource;
use App\Filament\Resources\Plans\PlanResource;
use App\Filament\Resources\PriceEstimates\PriceEstimateResource;
use App\Filament\Resources\PrivacyRequests\PrivacyRequestResource;
use App\Filament\Resources\ProductAliases\ProductAliasResource;
use App\Filament\Resources\ProductCategories\ProductCategoryResource;
use App\Filament\Resources\ProductMatchReviews\ProductMatchReviewResource;
use App\Filament\Resources\ProductModels\ProductModelResource;
use App\Filament\Resources\ProductVariantMarkets\ProductVariantMarketResource;
use App\Filament\Resources\ProductVariants\ProductVariantResource;
use App\Filament\Resources\RiskAssessments\RiskAssessmentResource;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Filament\Resources\TelegramConnections\TelegramConnectionResource;
use App\Filament\Resources\Usages\UsageResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\AiAnalysis;
use App\Models\Analysis;
use App\Models\BillingProviderEvent;
use App\Models\Brand;
use App\Models\BrokerRequestEvent;
use App\Models\ComparableSet;
use App\Models\Listing;
use App\Models\ListingSnapshot;
use App\Models\MarketplaceSource;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Plan;
use App\Models\PlatformAuditEvent;
use App\Models\PriceEstimate;
use App\Models\PrivacyRequest;
use App\Models\ProductAlias;
use App\Models\ProductCategory;
use App\Models\ProductMatch;
use App\Models\ProductModel;
use App\Models\ProductVariant;
use App\Models\ProductVariantMarket;
use App\Models\RiskAssessment;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function superAdmin(array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $user->forceFill(['is_super_admin' => true])->save();

    return $user->fresh();
}

/** @return array<string, mixed> */
function adminPricingEvidenceFixture(): array
{
    app(SyncMarketReferenceData::class)->sync();

    $requester = User::factory()->create();
    $organization = Organization::factory()->create([
        'name' => 'Pricing Operations Workspace',
    ]);
    $listing = Listing::factory()->create([
        'organization_id' => $organization,
        'created_by_user_id' => $requester,
        'title' => 'Pricing-visible camera body',
        'source_country_code' => 'DE',
        'target_country_code' => 'US',
    ]);
    $snapshot = ListingSnapshot::query()->create([
        'listing_id' => $listing->getKey(),
        'sequence' => 1,
        'captured_by_user_id' => $requester->getKey(),
        'captured_at' => now(),
        'source_url' => $listing->source_url,
        'external_id' => $listing->external_id,
        'marketplace_name' => $listing->marketplace_name,
        'marketplace_key' => $listing->marketplace_key,
        'title' => $listing->title,
        'description' => $listing->description,
        'asking_price_minor' => $listing->asking_price_minor,
        'currency_code' => $listing->currency_code,
        'seller_information' => $listing->seller_information,
        'location' => $listing->location,
        'source_country_code' => $listing->source_country_code,
        'target_country_code' => $listing->target_country_code,
        'status' => $listing->status,
        'notes' => $listing->notes,
        'raw_payload' => [],
        'content_hash' => hash('sha256', 'admin-price-estimate-snapshot'),
    ]);
    $analysis = Analysis::query()->create([
        'organization_id' => $organization->getKey(),
        'listing_id' => $listing->getKey(),
        'listing_snapshot_id' => $snapshot->getKey(),
        'requested_by_user_id' => $requester->getKey(),
        'analysis_type' => AnalysisType::Buy,
        'status' => AnalysisStatus::Completed,
        'source_country_code' => 'DE',
        'target_country_code' => 'US',
        'pipeline_version' => 'buy-analysis-pipeline:v1',
        'request_payload' => [],
        'request_hash' => hash('sha256', 'admin-price-estimate-request'),
        'result_payload' => [],
        'processing_attempts' => 1,
        'submitted_at' => now()->subMinutes(5),
        'finished_at' => now(),
    ]);
    $aiAnalysis = AiAnalysis::query()->create([
        'organization_id' => $organization->getKey(),
        'analysis_id' => $analysis->getKey(),
        'attempt_number' => 1,
        'status' => AiAnalysisStatus::Completed,
        'provider' => 'pricing-fixture-provider',
        'model' => 'pricing-fixture-model',
        'prompt_version' => 'prompt:v1',
        'input_hash' => hash('sha256', 'admin-price-estimate-ai-input'),
        'input_snapshot' => [],
        'result_json' => [],
        'validation_status' => AiValidationStatus::Valid,
        'confidence_basis_points' => 9000,
        'started_at' => now()->subMinutes(4),
        'completed_at' => now()->subMinutes(3),
    ]);
    $category = ProductCategory::query()->create([
        'name' => 'Pricing cameras',
        'slug' => 'pricing-cameras',
        'active' => true,
    ]);
    $brand = Brand::query()->create([
        'name' => 'Pricing Optics',
        'active' => true,
    ]);
    $productModel = ProductModel::query()->create([
        'brand_id' => $brand->getKey(),
        'product_category_id' => $category->getKey(),
        'name' => 'PX-7 Camera',
        'model_number' => 'PX-7',
        'canonical_key' => 'pricing-optics:px-7',
        'active' => true,
    ]);
    $productMatch = ProductMatch::query()->create([
        'organization_id' => $organization->getKey(),
        'analysis_id' => $analysis->getKey(),
        'ai_analysis_id' => $aiAnalysis->getKey(),
        'product_model_id' => $productModel->getKey(),
        'run_number' => 1,
        'status' => ProductMatchStatus::Matched,
        'review_status' => ProductMatchReviewStatus::NotRequired,
        'method' => 'exact_alias',
        'matcher_version' => 'matcher:v1',
        'input_hash' => hash('sha256', 'admin-price-estimate-match-input'),
        'match_key' => hash('sha256', 'admin-price-estimate-match-key'),
        'confidence_basis_points' => 9100,
        'candidate_snapshot' => [],
        'reason_codes' => [],
    ]);
    $comparableSet = ComparableSet::query()->create([
        'organization_id' => $organization->getKey(),
        'analysis_id' => $analysis->getKey(),
        'product_match_id' => $productMatch->getKey(),
        'run_number' => 1,
        'status' => ComparableSetStatus::Ready,
        'selector_version' => 'selector:v1',
        'input_hash' => hash('sha256', 'admin-price-estimate-set-input'),
        'selection_key' => hash('sha256', 'admin-price-estimate-selection-key'),
        'target_country_code' => 'US',
        'target_currency_code' => 'USD',
        'candidate_count' => 7,
        'included_count' => 5,
        'excluded_count' => 2,
        'minimum_required' => 3,
        'reason_codes' => [],
    ]);
    $privateInputHash = hash('sha256', 'private-price-input-hash-source');
    $privateEstimateKey = hash('sha256', 'private-price-estimate-key-source');
    $priceEstimate = PriceEstimate::query()->create([
        'organization_id' => $organization->getKey(),
        'analysis_id' => $analysis->getKey(),
        'comparable_set_id' => $comparableSet->getKey(),
        'run_number' => 1,
        'status' => PriceEstimateStatus::Estimated,
        'algorithm_version' => 'price-estimator:v2',
        'rate_resolver_version' => 'rate-resolver:v2',
        'input_hash' => $privateInputHash,
        'estimate_key' => $privateEstimateKey,
        'calculation_at' => now(),
        'target_country_code' => 'US',
        'target_currency_code' => 'USD',
        'input_count' => 7,
        'included_count' => 5,
        'outlier_count' => 1,
        'unresolved_count' => 1,
        'estimate_low_minor' => 12000,
        'estimate_minor' => 13500,
        'estimate_high_minor' => 15000,
        'median_minor' => 13400,
        'weighted_median_minor' => 13500,
        'q1_minor' => 12200,
        'q3_minor' => 14800,
        'mad_minor' => 1300,
        'dispersion_basis_points' => 1250,
        'confidence_basis_points' => 8450,
        'confidence_level' => PriceConfidenceLevel::High,
        'reason_codes' => ['private-price-reason-must-not-render'],
        'confidence_components' => ['private-price-component-must-not-render'],
        'input_snapshot' => ['private-price-input-must-not-render'],
    ]);

    return compact(
        'organization',
        'listing',
        'analysis',
        'productMatch',
        'comparableSet',
        'priceEstimate',
        'privateInputHash',
        'privateEstimateKey',
    );
}

test('ordinary and unverified users cannot access the Filament administration panel', function () {
    $ordinaryUser = User::factory()->create();
    $unverifiedAdmin = superAdmin(['email_verified_at' => null]);

    expect($ordinaryUser->canAccessPanel(Filament::getPanel('admin')))->toBeFalse()
        ->and($unverifiedAdmin->canAccessPanel(Filament::getPanel('admin')))->toBeFalse();

    $this->actingAs($ordinaryUser)
        ->get(route('filament.admin.pages.dashboard'))
        ->assertForbidden();

    $this->actingAs($unverifiedAdmin)
        ->get(route('filament.admin.pages.dashboard'))
        ->assertForbidden();
});

test('verified super administrators can access operational resources while resource creation remains disabled', function () {
    $this->seed(PlanSeeder::class);
    $admin = superAdmin();

    expect($admin->canAccessPanel(Filament::getPanel('admin')))->toBeTrue()
        ->and(UserResource::canCreate())->toBeFalse()
        ->and(OrganizationResource::canCreate())->toBeFalse()
        ->and(PlanResource::canCreate())->toBeFalse()
        ->and(AuditEventResource::canCreate())->toBeFalse()
        ->and(AnalysisOperationResource::canCreate())->toBeFalse()
        ->and(AnalysisResource::canCreate())->toBeFalse()
        ->and(AiAnalysisResource::canCreate())->toBeFalse()
        ->and(PriceEstimateResource::canCreate())->toBeFalse()
        ->and(RiskAssessmentResource::canCreate())->toBeFalse()
        ->and(ListingResource::canCreate())->toBeFalse()
        ->and(MarketplaceSourceResource::canCreate())->toBeFalse()
        ->and(ComparableMarketNormalizationResource::canCreate())->toBeFalse()
        ->and(BrokerRequestOfferResource::canCreate())->toBeFalse()
        ->and(BrokerRequestResource::canCreate())->toBeFalse()
        ->and(BrokerTransactionResource::canCreate())->toBeFalse()
        ->and(BrokerCommissionResource::canCreate())->toBeFalse()
        ->and(BrokerPaymentCaseResource::canCreate())->toBeFalse()
        ->and(BrokerReportResource::canCreate())->toBeFalse()
        ->and(PrivacyRequestResource::canCreate())->toBeFalse()
        ->and(BrandResource::canCreate())->toBeFalse()
        ->and(ProductCategoryResource::canCreate())->toBeFalse()
        ->and(ProductModelResource::canCreate())->toBeFalse()
        ->and(ProductVariantResource::canCreate())->toBeFalse()
        ->and(ProductVariantMarketResource::canCreate())->toBeFalse()
        ->and(ProductAliasResource::canCreate())->toBeFalse()
        ->and(ProductMatchReviewResource::canCreate())->toBeFalse();

    $this->actingAs($admin)
        ->get(route('filament.admin.pages.dashboard'))
        ->assertOk()
        ->assertSee('Procura Operations')
        ->assertSeeText('Broker lifecycle attention');

    foreach ([
        UserResource::class,
        OrganizationResource::class,
        MembershipResource::class,
        PlanResource::class,
        PlanFeatureResource::class,
        SubscriptionResource::class,
        UsageResource::class,
        CountryResource::class,
        CurrencyResource::class,
        BrandResource::class,
        ProductCategoryResource::class,
        ProductModelResource::class,
        ProductVariantResource::class,
        ProductVariantMarketResource::class,
        ProductAliasResource::class,
        AuditEventResource::class,
        AnalysisOperationResource::class,
        AnalysisResource::class,
        AiAnalysisResource::class,
        PriceEstimateResource::class,
        RiskAssessmentResource::class,
        ListingResource::class,
        MarketplaceSourceResource::class,
        NotificationDeliveryResource::class,
        TelegramConnectionResource::class,
        BillingProviderEventResource::class,
        ComparableMarketNormalizationResource::class,
        BrokerRequestOfferResource::class,
        BrokerRequestResource::class,
        BrokerTransactionResource::class,
        BrokerCommissionResource::class,
        BrokerPaymentCaseResource::class,
        BrokerReportResource::class,
        PrivacyRequestResource::class,
        ProductMatchReviewResource::class,
    ] as $resource) {
        $this->actingAs($admin)
            ->get($resource::getUrl())
            ->assertOk();
    }
});

test('marketplace source explorer exposes compliance projections without internal governance data', function () {
    $source = MarketplaceSource::factory()->create([
        'key' => 'regional_partner_feed',
        'name' => 'Regional Partner Feed',
        'connector_type' => 'partner_feed',
        'capabilities' => ['import', 'incremental_sync'],
        'geographic_coverage' => 'Europe and North America',
        'cross_border_supported' => true,
        'compliance_status' => 'pending_review',
        'terms_reviewed_at' => '2026-08-01',
        'legal_basis' => 'private-legal-basis-must-not-render',
        'allowed_operations' => 'private-allowed-operations-must-not-render',
        'prohibited_operations' => 'private-prohibited-operations-must-not-render',
        'data_retention_rules' => 'private-retention-rules-must-not-render',
        'contact_person' => 'private-contact-must-not-render',
        'review_notes' => 'private-review-notes-must-not-render',
        'reliability_score' => 82,
        'freshness_score' => 74,
        'completeness_score' => 91,
        'asking_price_only' => true,
        'transaction_price_supported' => false,
        'active' => true,
    ]);

    $this->actingAs(User::factory()->create())
        ->get(MarketplaceSourceResource::getUrl())
        ->assertForbidden();

    $this->actingAs(superAdmin())
        ->get(MarketplaceSourceResource::getUrl())
        ->assertOk()
        ->assertSeeText($source->name)
        ->assertSeeText($source->key)
        ->assertSeeText('Partner feed')
        ->assertSeeText('Pending review')
        ->assertSeeText('Europe and North America')
        ->assertSeeText('82 / 100')
        ->assertSeeText('74 / 100')
        ->assertSeeText('91 / 100')
        ->assertDontSee('private-legal-basis-must-not-render')
        ->assertDontSee('private-allowed-operations-must-not-render')
        ->assertDontSee('private-prohibited-operations-must-not-render')
        ->assertDontSee('private-retention-rules-must-not-render')
        ->assertDontSee('private-contact-must-not-render')
        ->assertDontSee('private-review-notes-must-not-render');
});

test('listing explorer exposes bounded global support fields without private listing content', function () {
    app(SyncMarketReferenceData::class)->sync();

    $creator = User::factory()->create();
    $organization = Organization::factory()->create([
        'name' => 'Global Listing Support Workspace',
    ]);
    Listing::factory()->create([
        'organization_id' => $organization,
        'created_by_user_id' => $creator,
        'source_url' => 'https://private.example/listing-source-must-not-render',
        'external_id' => 'TOKYO-DRILL-42',
        'marketplace_name' => 'Tokyo Tool Market',
        'title' => 'Makita cordless drill set',
        'description' => 'private-description-must-not-render',
        'asking_price_minor' => 12345,
        'currency_code' => 'JPY',
        'seller_information' => 'private-seller-must-not-render',
        'location' => 'private-location-must-not-render',
        'source_country_code' => 'JP',
        'target_country_code' => 'US',
        'status' => 'reserved',
        'notes' => 'private-notes-must-not-render',
        'raw_input' => ['private' => 'raw-input-must-not-render'],
    ]);

    $this->actingAs(User::factory()->create())
        ->get(ListingResource::getUrl())
        ->assertForbidden();

    $this->actingAs(superAdmin())
        ->get(ListingResource::getUrl())
        ->assertOk()
        ->assertSeeText('Global Listing Support Workspace')
        ->assertSeeText('Makita cordless drill set')
        ->assertSeeText('Tokyo Tool Market')
        ->assertSeeText('12,345 JPY')
        ->assertSeeText('JP -> US')
        ->assertSeeText('Reserved')
        ->assertDontSee('listing-source-must-not-render')
        ->assertDontSee('private-description-must-not-render')
        ->assertDontSee('private-seller-must-not-render')
        ->assertDontSee('private-location-must-not-render')
        ->assertDontSee('private-notes-must-not-render')
        ->assertDontSee('raw-input-must-not-render');
});

test('analysis explorer exposes safe pipeline projections only to verified super administrators', function () {
    app(SyncMarketReferenceData::class)->sync();

    $requester = User::factory()->create();
    $organization = Organization::factory()->create([
        'name' => 'Analysis Support Workspace',
    ]);
    $listing = Listing::factory()->create([
        'organization_id' => $organization,
        'created_by_user_id' => $requester,
        'title' => 'Support-visible cordless drill',
        'source_country_code' => 'DE',
        'target_country_code' => 'AT',
    ]);
    $snapshot = ListingSnapshot::query()->create([
        'listing_id' => $listing->getKey(),
        'sequence' => 1,
        'captured_by_user_id' => $requester->getKey(),
        'captured_at' => now(),
        'source_url' => $listing->source_url,
        'external_id' => $listing->external_id,
        'marketplace_name' => $listing->marketplace_name,
        'marketplace_key' => $listing->marketplace_key,
        'title' => $listing->title,
        'description' => $listing->description,
        'asking_price_minor' => $listing->asking_price_minor,
        'currency_code' => $listing->currency_code,
        'seller_information' => $listing->seller_information,
        'location' => $listing->location,
        'source_country_code' => $listing->source_country_code,
        'target_country_code' => $listing->target_country_code,
        'status' => $listing->status,
        'notes' => $listing->notes,
        'raw_payload' => ['private' => 'snapshot-secret-must-not-render'],
        'content_hash' => hash('sha256', 'admin-analysis-explorer-snapshot'),
    ]);
    Analysis::query()->create([
        'organization_id' => $organization->getKey(),
        'listing_id' => $listing->getKey(),
        'listing_snapshot_id' => $snapshot->getKey(),
        'requested_by_user_id' => $requester->getKey(),
        'analysis_type' => AnalysisType::Buy,
        'status' => AnalysisStatus::Completed,
        'source_country_code' => 'DE',
        'target_country_code' => 'AT',
        'pipeline_version' => 'buy-analysis-pipeline:v1',
        'request_payload' => ['private' => 'request-secret-must-not-render'],
        'request_hash' => hash('sha256', 'admin-analysis-explorer-request'),
        'result_payload' => ['private' => 'result-secret-must-not-render'],
        'processing_attempts' => 1,
        'submitted_at' => now()->subMinute(),
        'finished_at' => now(),
        'last_error_message' => 'internal-error-must-not-render',
    ]);

    $this->actingAs(User::factory()->create())
        ->get(AnalysisResource::getUrl())
        ->assertForbidden();

    $this->actingAs(superAdmin())
        ->get(AnalysisResource::getUrl())
        ->assertOk()
        ->assertSeeText('Analysis Support Workspace')
        ->assertSeeText('Support-visible cordless drill')
        ->assertSeeText('Buy analysis')
        ->assertSeeText('DE -> AT')
        ->assertSeeText('Completed')
        ->assertDontSee('snapshot-secret-must-not-render')
        ->assertDontSee('request-secret-must-not-render')
        ->assertDontSee('result-secret-must-not-render')
        ->assertDontSee('internal-error-must-not-render');
});

test('AI analysis explorer exposes operational attempt data without private provider evidence', function () {
    app(SyncMarketReferenceData::class)->sync();

    $requester = User::factory()->create();
    $organization = Organization::factory()->create([
        'name' => 'AI Operations Workspace',
    ]);
    $listing = Listing::factory()->create([
        'organization_id' => $organization,
        'created_by_user_id' => $requester,
        'title' => 'AI-visible pressure washer',
        'source_country_code' => 'DE',
        'target_country_code' => 'FR',
    ]);
    $snapshot = ListingSnapshot::query()->create([
        'listing_id' => $listing->getKey(),
        'sequence' => 1,
        'captured_by_user_id' => $requester->getKey(),
        'captured_at' => now(),
        'source_url' => $listing->source_url,
        'external_id' => $listing->external_id,
        'marketplace_name' => $listing->marketplace_name,
        'marketplace_key' => $listing->marketplace_key,
        'title' => $listing->title,
        'description' => $listing->description,
        'asking_price_minor' => $listing->asking_price_minor,
        'currency_code' => $listing->currency_code,
        'seller_information' => $listing->seller_information,
        'location' => $listing->location,
        'source_country_code' => $listing->source_country_code,
        'target_country_code' => $listing->target_country_code,
        'status' => $listing->status,
        'notes' => $listing->notes,
        'raw_payload' => [],
        'content_hash' => hash('sha256', 'admin-ai-analysis-snapshot'),
    ]);
    $analysis = Analysis::query()->create([
        'organization_id' => $organization->getKey(),
        'listing_id' => $listing->getKey(),
        'listing_snapshot_id' => $snapshot->getKey(),
        'requested_by_user_id' => $requester->getKey(),
        'analysis_type' => AnalysisType::Buy,
        'status' => AnalysisStatus::Completed,
        'source_country_code' => 'DE',
        'target_country_code' => 'FR',
        'pipeline_version' => 'buy-analysis-pipeline:v1',
        'request_payload' => [],
        'request_hash' => hash('sha256', 'admin-ai-analysis-request'),
        'result_payload' => [],
        'processing_attempts' => 1,
        'submitted_at' => now()->subMinutes(3),
        'finished_at' => now(),
    ]);
    AiAnalysis::query()->create([
        'organization_id' => $organization->getKey(),
        'analysis_id' => $analysis->getKey(),
        'attempt_number' => 1,
        'status' => AiAnalysisStatus::Completed,
        'provider' => 'operations-provider',
        'model' => 'operations-model-v7',
        'prompt_version' => 'prompt:v7',
        'input_hash' => hash('sha256', 'private-input-hash-source'),
        'input_snapshot' => ['private' => 'private-ai-input-must-not-render'],
        'result_json' => ['private' => 'private-ai-result-must-not-render'],
        'validation_status' => AiValidationStatus::Valid,
        'confidence_basis_points' => 8765,
        'tokens_in' => 123456,
        'tokens_out' => 654321,
        'estimated_cost_minor' => 7654321,
        'estimated_cost_currency' => 'EUR',
        'started_at' => now()->subMinutes(2),
        'completed_at' => now(),
        'error' => 'private-provider-error-must-not-render',
    ]);

    $this->actingAs(User::factory()->create())
        ->get(AiAnalysisResource::getUrl())
        ->assertForbidden();

    $this->actingAs(superAdmin())
        ->get(AiAnalysisResource::getUrl())
        ->assertOk()
        ->assertSeeText('AI Operations Workspace')
        ->assertSeeText('AI-visible pressure washer')
        ->assertSeeText('Completed')
        ->assertSeeText('Valid')
        ->assertSeeText('operations-provider')
        ->assertSeeText('operations-model-v7')
        ->assertSeeText('prompt:v7')
        ->assertSeeText('87.65%')
        ->assertSeeText('2 minutes')
        ->assertDontSee('private-ai-input-must-not-render')
        ->assertDontSee('private-ai-result-must-not-render')
        ->assertDontSee('private-provider-error-must-not-render')
        ->assertDontSee('123456')
        ->assertDontSee('654321')
        ->assertDontSee('7654321');
});

test('price estimate explorer exposes bounded pricing projections without private evidence', function () {
    $fixture = adminPricingEvidenceFixture();

    $this->actingAs(User::factory()->create())
        ->get(PriceEstimateResource::getUrl())
        ->assertForbidden();

    $this->actingAs(superAdmin())
        ->get(PriceEstimateResource::getUrl())
        ->assertOk()
        ->assertSeeText('Pricing Operations Workspace')
        ->assertSeeText('Pricing-visible camera body')
        ->assertSeeText('Estimated')
        ->assertSeeText('United States (US) - USD')
        ->assertSeeText('135.00 USD')
        ->assertSeeText('120.00 USD - 150.00 USD')
        ->assertSeeText('84.50% - High')
        ->assertDontSee($fixture['privateInputHash'])
        ->assertDontSee($fixture['privateEstimateKey'])
        ->assertDontSee('private-price-reason-must-not-render')
        ->assertDontSee('private-price-component-must-not-render')
        ->assertDontSee('private-price-input-must-not-render');
});

test('risk assessment explorer exposes critical risk projections without private evidence', function () {
    $fixture = adminPricingEvidenceFixture();
    $privateInputHash = hash('sha256', 'private-risk-input-hash-source');
    $privateAssessmentKey = hash('sha256', 'private-risk-assessment-key-source');
    $assessment = RiskAssessment::query()->create([
        'organization_id' => $fixture['organization']->getKey(),
        'analysis_id' => $fixture['analysis']->getKey(),
        'product_match_id' => $fixture['productMatch']->getKey(),
        'comparable_set_id' => $fixture['comparableSet']->getKey(),
        'price_estimate_id' => $fixture['priceEstimate']->getKey(),
        'run_number' => 1,
        'status' => RiskAssessmentStatus::Assessed,
        'evaluator_version' => 'risk-evaluator:v2',
        'input_hash' => $privateInputHash,
        'assessment_key' => $privateAssessmentKey,
        'calculation_at' => now(),
        'score' => 80,
        'level' => RiskLevel::Critical,
        'confidence_basis_points' => 6200,
        'confidence_level' => RiskConfidenceLevel::Medium,
        'signal_count' => 2,
        'unknown_count' => 1,
        'reason_codes' => ['private-risk-reason-must-not-render'],
        'confidence_components' => ['private-risk-component-must-not-render'],
        'verification_actions' => ['private-risk-action-must-not-render'],
        'input_snapshot' => ['private-risk-input-must-not-render'],
    ]);
    $assessment->signals()->createMany([
        [
            'position' => 1,
            'code' => 'private-risk-signal-code-must-not-render',
            'category' => 'seller',
            'severity' => 'critical',
            'is_unknown' => false,
            'weight_points' => 80,
            'score_contribution' => 80,
            'evidence_snapshot' => ['private-risk-signal-evidence-must-not-render'],
            'source' => 'private-risk-signal-source-must-not-render',
            'confidence_basis_points' => 8000,
            'verification_action' => 'private-risk-signal-action-must-not-render',
        ],
        [
            'position' => 2,
            'code' => 'private-risk-unknown-code-must-not-render',
            'category' => 'product',
            'severity' => 'high',
            'is_unknown' => true,
            'weight_points' => 20,
            'score_contribution' => 0,
            'evidence_snapshot' => ['private-risk-unknown-evidence-must-not-render'],
            'source' => 'private-risk-unknown-source-must-not-render',
            'confidence_basis_points' => 0,
            'verification_action' => 'private-risk-unknown-action-must-not-render',
        ],
    ]);

    $this->actingAs(User::factory()->create())
        ->get(RiskAssessmentResource::getUrl())
        ->assertForbidden();

    $this->actingAs(superAdmin())
        ->get(RiskAssessmentResource::getUrl())
        ->assertOk()
        ->assertSeeText('Pricing Operations Workspace')
        ->assertSeeText('Pricing-visible camera body')
        ->assertSeeText('DE -> US')
        ->assertSeeText('Assessed')
        ->assertSeeText('80 / 100')
        ->assertSeeText('Critical')
        ->assertSeeText('62.00% - Medium')
        ->assertDontSee($privateInputHash)
        ->assertDontSee($privateAssessmentKey)
        ->assertDontSee('private-risk-reason-must-not-render')
        ->assertDontSee('private-risk-component-must-not-render')
        ->assertDontSee('private-risk-action-must-not-render')
        ->assertDontSee('private-risk-signal-code-must-not-render')
        ->assertDontSee('private-risk-signal-evidence-must-not-render')
        ->assertDontSee('private-risk-signal-source-must-not-render')
        ->assertDontSee('private-risk-signal-action-must-not-render')
        ->assertDontSee('private-risk-unknown-code-must-not-render')
        ->assertDontSee('private-risk-unknown-evidence-must-not-render')
        ->assertDontSee('private-risk-unknown-source-must-not-render')
        ->assertDontSee('private-risk-unknown-action-must-not-render');
});

test('catalog explorer exposes canonical relationships only to verified super administrators', function () {
    app(SyncMarketReferenceData::class)->sync();

    $category = ProductCategory::query()->create([
        'name' => 'Cordless drills',
        'slug' => 'cordless-drills',
        'active' => true,
    ]);
    $brand = Brand::query()->create([
        'name' => 'Atlas Tools',
        'active' => true,
    ]);
    $productModel = ProductModel::query()->create([
        'brand_id' => $brand->getKey(),
        'product_category_id' => $category->getKey(),
        'name' => 'Atlas Pro Drill',
        'model_number' => 'APD-18',
        'canonical_key' => 'atlas-tools:apd-18',
        'active' => true,
    ]);
    $variant = ProductVariant::query()->create([
        'product_model_id' => $productModel->getKey(),
        'name' => 'EU kit',
        'canonical_key' => 'atlas-tools:apd-18:eu-kit',
        'sku' => 'APD-18-EU',
        'active' => true,
    ]);
    ProductVariantMarket::query()->create([
        'product_variant_id' => $variant->getKey(),
        'country_code' => 'DE',
        'market_model_number' => 'APD-18-DE',
        'voltage_millivolts' => 230000,
        'plug_type' => 'F',
        'measurement_system' => 'metric',
        'warranty_applicable' => true,
    ]);
    ProductAlias::query()->create([
        'product_model_id' => $productModel->getKey(),
        'product_variant_id' => $variant->getKey(),
        'alias' => 'Atlas Akkubohrer 18V',
        'locale' => 'de-DE',
        'country_code' => 'DE',
        'source' => 'catalog',
        'active' => true,
    ]);

    $resources = [
        BrandResource::class,
        ProductCategoryResource::class,
        ProductModelResource::class,
        ProductVariantResource::class,
        ProductVariantMarketResource::class,
        ProductAliasResource::class,
    ];

    foreach ($resources as $resource) {
        $this->actingAs(User::factory()->create())
            ->get($resource::getUrl())
            ->assertForbidden();
    }

    $admin = superAdmin();

    $this->actingAs($admin)->get(BrandResource::getUrl())
        ->assertOk()
        ->assertSeeText('Atlas Tools');
    $this->actingAs($admin)->get(ProductCategoryResource::getUrl())
        ->assertOk()
        ->assertSeeText('Cordless drills');
    $this->actingAs($admin)->get(ProductModelResource::getUrl())
        ->assertOk()
        ->assertSeeText('Atlas Pro Drill')
        ->assertSeeText('APD-18');
    $this->actingAs($admin)->get(ProductVariantResource::getUrl())
        ->assertOk()
        ->assertSeeText('EU kit')
        ->assertSeeText('APD-18-EU');
    $this->actingAs($admin)->get(ProductVariantMarketResource::getUrl())
        ->assertOk()
        ->assertSeeText('APD-18-DE')
        ->assertSeeText('230 V');
    $this->actingAs($admin)->get(ProductAliasResource::getUrl())
        ->assertOk()
        ->assertSeeText('Atlas Akkubohrer 18V')
        ->assertSeeText('de-DE');
});

test('privacy operations expose workflow evidence without internal request hashes', function () {
    $subject = User::factory()->create([
        'email' => 'privacy-subject@example.test',
    ]);
    app(CreatePrivacyRequest::class)->create($subject, [
        'type' => 'data_export',
        'residence_country_code' => null,
        'reason' => 'Provide a portable copy of the account data.',
        'idempotency_key' => (string) Str::uuid(),
    ]);
    $privacyRequest = PrivacyRequest::query()->sole();

    $this->actingAs(User::factory()->create())
        ->get(PrivacyRequestResource::getUrl())
        ->assertForbidden();

    $this->actingAs(superAdmin())
        ->get(PrivacyRequestResource::getUrl())
        ->assertOk()
        ->assertSeeText('privacy-subject@example.test')
        ->assertSeeText('Data export')
        ->assertSeeText('Requested')
        ->assertSeeText('Request submitted')
        ->assertDontSee($privacyRequest->requester_email_hash)
        ->assertDontSee($privacyRequest->payload_hash)
        ->assertDontSee($privacyRequest->idempotency_key);
});

test('broker operations expose bounded evidence without internal replay data', function () {
    app(SyncMarketReferenceData::class)->sync();
    $this->seed(PlanSeeder::class);
    $requester = User::factory()->create();
    $organization = Organization::factory()->create([
        'name' => 'Broker Operations Workspace',
    ]);
    OrganizationMembership::factory()->owner()->create([
        'organization_id' => $organization,
        'user_id' => $requester,
    ]);
    $requester->update([
        'current_organization_id' => $organization->getKey(),
    ]);
    DB::table('organization_plan_assignments')->insert([
        'organization_id' => $organization->getKey(),
        'plan_id' => Plan::query()
            ->where('code', 'business')
            ->valueOrFail('id'),
        'starts_at' => now()->subMinute(),
        'ends_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $created = app(CreateBrokerRequest::class)->create(
        $organization,
        $requester,
        [
            'title' => 'Industrial robot sourcing brief',
            'product_category_id' => null,
            'product_description' => (
                'A production-ready six-axis robot with maintenance records.'
            ),
            'brand_preference' => null,
            'model_preference' => null,
            'condition_preference' => 'used',
            'quantity' => 1,
            'budget_max_minor' => 2500000,
            'budget_currency_code' => 'EUR',
            'target_country_codes' => ['DE'],
            'needed_by' => now()->addMonths(2)->toDateString(),
            'notes' => null,
        ],
        (string) Str::uuid(),
    );
    $transition = app(TransitionBrokerRequest::class);
    $submitted = $transition->submit(
        $created->brokerRequest,
        $requester,
        $created->event->getKey(),
        (string) Str::uuid(),
    );
    $admin = superAdmin();
    $reviewed = $transition->operatorTransition(
        $created->brokerRequest->fresh(),
        $admin,
        BrokerRequestStatus::Reviewing,
        $submitted->event->getKey(),
        (string) Str::uuid(),
        'operator_review_started',
        'case:broker-admin-001',
    );
    $searching = $transition->operatorTransition(
        $created->brokerRequest->fresh(),
        $admin,
        BrokerRequestStatus::Searching,
        $reviewed->event->getKey(),
        (string) Str::uuid(),
        'operator_search_started',
        'case:broker-admin-search-001',
    );
    config([
        'broker.offers_enabled' => true,
        'broker.transactions_enabled' => true,
        'broker.payment_cases_enabled' => true,
        'broker.commission_rule_version' => 'broker-commission:v1',
        'broker.commission_rate_basis_points' => 250,
    ]);
    $offer = app(PresentBrokerRequestOffer::class)->execute(
        request: $created->brokerRequest->fresh(),
        actor: $admin,
        expectedRequestEventId: $searching->event->getKey(),
        idempotencyKey: (string) Str::uuid(),
        evidenceReference: 'quote:broker-admin-offer-001',
        input: [
            'supplier_display_name' => 'Admin-visible supplier alias',
            'supplier_reference' => 'vault:supplier-ref-001',
            'item_description' => (
                'Inspected six-axis industrial robot with loading included.'
            ),
            'condition' => 'used',
            'quantity' => 1,
            'unit_price_minor' => 2200000,
            'shipping_cost_minor' => 100000,
            'tax_duty_cost_minor' => 50000,
            'other_cost_minor' => 0,
            'currency_code' => 'EUR',
            'origin_country_code' => 'DE',
            'estimated_delivery_date' => now()->addMonth()->toDateString(),
            'valid_until' => now()->addWeek()->toIso8601String(),
            'warranty_months' => 3,
            'return_policy_summary' => 'Documented material mismatch only.',
        ],
    );
    $accepted = app(AcceptBrokerRequestOffer::class)->execute(
        request: $created->brokerRequest->fresh(),
        offer: $offer->offer,
        actor: $requester,
        expectedRequestEventId: $offer->requestEvent->getKey(),
        expectedOfferEventId: $offer->offerEvent->getKey(),
        idempotencyKey: (string) Str::uuid(),
    );
    $payment = app(TransitionBrokerTransaction::class)->execute(
        transaction: $accepted->transaction,
        actor: $admin,
        target: BrokerTransactionStatus::PaymentConfirmed,
        expectedCurrentEventId: $accepted->transaction->current_event_id,
        idempotencyKey: (string) Str::uuid(),
        reasonCode: 'external_payment_confirmed',
        evidenceReference: 'payment-ledger:admin-payment-001',
    );
    $paymentCase = app(OpenBrokerPaymentCase::class)->execute(
        transaction: $payment->transaction,
        actor: $admin,
        type: BrokerPaymentCaseType::Refund,
        requestedAmountMinor: 10000,
        expectedTransactionEventId: $payment->transactionEvent->getKey(),
        idempotencyKey: (string) Str::uuid(),
        externalCaseReference: 'support:admin-refund-001',
        reasonCode: 'refund_case_opened',
        evidenceReference: 'case-evidence:admin-refund-001',
    );

    $this->actingAs(User::factory()->create())
        ->get(BrokerRequestResource::getUrl())
        ->assertForbidden();
    $this->actingAs(User::factory()->create())
        ->get(BrokerRequestOfferResource::getUrl())
        ->assertForbidden();
    $this->actingAs(User::factory()->create())
        ->get(BrokerTransactionResource::getUrl())
        ->assertForbidden();
    $this->actingAs(User::factory()->create())
        ->get(BrokerCommissionResource::getUrl())
        ->assertForbidden();
    $this->actingAs(User::factory()->create())
        ->get(BrokerReportResource::getUrl())
        ->assertForbidden();
    $this->actingAs(User::factory()->create())
        ->get(BrokerPaymentCaseResource::getUrl())
        ->assertForbidden();

    $this->actingAs($admin)
        ->get(BrokerRequestResource::getUrl())
        ->assertOk()
        ->assertSeeText('Broker Operations Workspace')
        ->assertSeeText('Industrial robot sourcing brief')
        ->assertSeeText('Offer accepted by requester')
        ->assertDontSee($reviewed->event->payload_hash)
        ->assertDontSee($reviewed->event->request_hash)
        ->assertDontSee($reviewed->event->idempotency_key);

    $this->actingAs($admin)
        ->get(BrokerRequestOfferResource::getUrl())
        ->assertOk()
        ->assertSeeText('Admin-visible supplier alias')
        ->assertSeeText('vault:supplier-ref-001')
        ->assertSeeText('Accepted by requester')
        ->assertDontSee($offer->offerEvent->payload_hash)
        ->assertDontSee($offer->offerEvent->offer_hash)
        ->assertDontSee($offer->offerEvent->idempotency_key);

    $this->actingAs($admin)
        ->get(BrokerTransactionResource::getUrl())
        ->assertOk()
        ->assertSeeText('Payment confirmed')
        ->assertSeeText('Pending')
        ->assertDontSee($accepted->transaction->currentEvent->payload_hash)
        ->assertDontSee($accepted->transaction->currentEvent->transaction_hash)
        ->assertDontSee($accepted->transaction->currentEvent->idempotency_key);

    $this->actingAs($admin)
        ->get(BrokerCommissionResource::getUrl())
        ->assertOk()
        ->assertSeeText('broker-commission:v1')
        ->assertSeeText('Pending')
        ->assertDontSee($accepted->commission->currentEvent->payload_hash)
        ->assertDontSee($accepted->commission->currentEvent->commission_hash)
        ->assertDontSee($accepted->commission->currentEvent->idempotency_key);

    $this->actingAs($admin)
        ->get(BrokerPaymentCaseResource::getUrl())
        ->assertOk()
        ->assertSeeText('Refund')
        ->assertSeeText('support:admin-refund-001')
        ->assertSeeText('case-evidence:admin-refund-001')
        ->assertDontSee($paymentCase->event->payload_hash)
        ->assertDontSee($paymentCase->event->payment_case_hash)
        ->assertDontSee($paymentCase->event->idempotency_key);

    expect(BrokerRequestEvent::query()->count())->toBe(6);
});

test('billing operations expose safe translated events only to verified super administrators', function () {
    $organization = Organization::factory()->create([
        'name' => 'Billing Operations Workspace',
    ]);
    $event = BillingProviderEvent::query()->create([
        'provider' => 'stripe',
        'provider_event_id' => 'evt_sensitive_provider_event',
        'organization_id' => $organization->getKey(),
        'event_type' => 'customer.subscription.updated',
        'provider_customer_id' => 'cus_sensitive_customer',
        'provider_subscription_id' => 'sub_sensitive_subscription',
        'provider_price_id' => 'price_sensitive_price',
        'provider_status' => 'active',
        'payload_sha256' => str_repeat('a', 64),
        'livemode' => true,
        'outcome' => 'applied',
        'reason_code' => 'paid_plan_projected',
        'projected_plan_code' => 'pro',
        'occurred_at' => now(),
        'processed_at' => now(),
    ]);

    $this->actingAs(User::factory()->create())
        ->get(BillingProviderEventResource::getUrl())
        ->assertForbidden();

    $this->actingAs(superAdmin())
        ->get(BillingProviderEventResource::getUrl())
        ->assertOk()
        ->assertSeeText('Billing Operations Workspace')
        ->assertSeeText('Subscription updated')
        ->assertSeeText('Paid plan projected')
        ->assertDontSee($event->provider_event_id)
        ->assertDontSee($event->provider_customer_id)
        ->assertDontSee($event->provider_subscription_id)
        ->assertDontSee($event->provider_price_id)
        ->assertDontSee($event->payload_sha256);
});

test('admin translation catalogs have one complete shared key contract', function () {
    $catalogs = [
        'en' => lang_path('en/admin.php'),
        'de' => lang_path('de/admin.php'),
        'es' => lang_path('es/admin.php'),
        'fr' => lang_path('fr/admin.php'),
        'sr_Latn' => lang_path('sr_Latn/admin.php'),
    ];
    $referenceKeys = array_keys(Arr::dot(require $catalogs['en']));

    foreach ($catalogs as $locale => $path) {
        $translations = Arr::dot(require $path);

        expect(array_keys($translations))
            ->toBe($referenceKeys, "Admin translation keys differ for {$locale}.")
            ->and(array_filter(
                $translations,
                static fn (mixed $value): bool => ! is_string($value) || trim($value) === '',
            ))
            ->toBe([], "Admin translations contain empty values for {$locale}.");
    }
});

test('Filament admin controls use localized application overrides without English fallbacks', function (
    SupportedLocale $locale,
    string $skipToContent,
    string $theme,
    string $navigation,
    string $topbar,
    string $closeNotification,
    string $yes,
    string $no,
    string $results,
) {
    App::setLocale($locale->laravelLocale());

    expect(__('filament-panels::layout.skip_to_content.label'))->toBe($skipToContent)
        ->and(__('filament-panels::layout.actions.theme_switcher.label'))->toBe($theme)
        ->and(__('filament-panels::layout.navigation.label'))->toBe($navigation)
        ->and(__('filament-panels::layout.topbar.label'))->toBe($topbar)
        ->and(__('filament-notifications::notification.actions.close.label'))->toBe($closeNotification)
        ->and(__('filament-tables::table.columns.icon.boolean.true'))->toBe($yes)
        ->and(__('filament-tables::table.columns.icon.boolean.false'))->toBe($no)
        ->and(trans_choice('filament-tables::table.result_count', 6, ['count' => 6]))->toBe($results);
})->with([
    'English controls' => [
        SupportedLocale::English,
        'Skip to content',
        'Theme',
        'Sidebar navigation',
        'Topbar',
        'Close notification',
        'Yes',
        'No',
        '6 results',
    ],
    'German controls' => [
        SupportedLocale::German,
        'Zum Inhalt springen',
        'Darstellung',
        'Seitennavigation',
        'Kopfzeile',
        'Benachrichtigung schließen',
        'Ja',
        'Nein',
        '6 Ergebnisse',
    ],
    'Spanish controls' => [
        SupportedLocale::Spanish,
        'Saltar al contenido',
        'Tema',
        'Barra de navegación lateral',
        'Barra superior',
        'Cerrar notificación',
        'Sí',
        'No',
        '6 resultados',
    ],
    'French controls' => [
        SupportedLocale::French,
        'Aller au contenu',
        'Thème',
        'Navigation latérale',
        'Barre supérieure',
        'Fermer la notification',
        'Oui',
        'Non',
        '6 résultats',
    ],
    'Serbian Latin controls' => [
        SupportedLocale::SerbianLatin,
        'Preskoči na sadržaj',
        'Tema',
        'Navigacija bočne trake',
        'Gornja traka',
        'Zatvori obaveštenje',
        'Da',
        'Ne',
        '6 rezultata',
    ],
]);

test('the admin panel follows the authenticated personal interface locale', function (
    SupportedLocale $locale,
    string $serverLocale,
    string $brand,
    string $users,
    string $identity,
    string $accounts,
    string $dashboard,
    string $readiness,
) {
    $admin = superAdmin(['preferred_locale' => $locale]);

    $this->actingAs($admin)
        ->get(route('filament.admin.pages.dashboard'))
        ->assertOk()
        ->assertSeeText($brand)
        ->assertSeeText($users)
        ->assertSeeText($identity)
        ->assertSeeText($accounts)
        ->assertSeeText($dashboard)
        ->assertSeeText($readiness);

    expect(app()->getLocale())->toBe(config('app.locale'));

    try {
        App::setLocale($serverLocale);

        expect(UserResource::getNavigationLabel())->toBe($users);
    } finally {
        App::setLocale(config('app.locale'));
    }
})->with([
    'English' => [
        SupportedLocale::English,
        'en',
        'Procura Operations',
        'Users',
        'Identity',
        'Registered accounts',
        'Dashboard',
        'Core ready',
    ],
    'German' => [
        SupportedLocale::German,
        'de',
        'Procura-Betrieb',
        'Benutzer',
        'Identität',
        'Registrierte Konten',
        'Dashboard',
        'Basis bereit',
    ],
    'Spanish' => [
        SupportedLocale::Spanish,
        'es',
        'Operaciones de Procura',
        'Usuarios',
        'Identidad',
        'Cuentas registradas',
        'Escritorio',
        'Núcleo preparado',
    ],
    'French' => [
        SupportedLocale::French,
        'fr',
        'Opérations Procura',
        'Utilisateurs',
        'Identité',
        'Comptes enregistrés',
        'Tableau de bord',
        'Socle prêt',
    ],
    'Serbian Latin' => [
        SupportedLocale::SerbianLatin,
        'sr_Latn',
        'Procura operacije',
        'Korisnici',
        'Identitet',
        'Registrovani nalozi',
        'Nadzorna tabla',
        'Osnova spremna',
    ],
]);

test('the guest admin login resolves a supported browser locale without persisting it', function () {
    $this->withHeader('Accept-Language', 'fr-FR,fr;q=0.9,en;q=0.8')
        ->get(route('filament.admin.auth.login'))
        ->assertOk()
        ->assertSeeText('Opérations Procura')
        ->assertSeeText('Connectez-vous à votre compte')
        ->assertSeeText('Adresse e-mail');

    expect(app()->getLocale())->toBe(config('app.locale'));
});

test('organization plan assignment is super-admin only transactional and audited', function () {
    $this->seed(PlanSeeder::class);

    $organization = Organization::factory()->create();
    $starter = Plan::query()->where('code', 'starter')->firstOrFail();
    $pro = Plan::query()->where('code', 'pro')->firstOrFail();
    $ordinaryUser = User::factory()->create();
    $unverifiedAdmin = superAdmin(['email_verified_at' => null]);
    $admin = superAdmin();
    $assign = app(AssignOrganizationPlan::class);

    expect(fn () => $assign->assign(
        $organization,
        $starter,
        $ordinaryUser,
        'Attempted unauthorized plan assignment.',
    ))->toThrow(AuthorizationException::class);

    expect(fn () => $assign->assign(
        $organization,
        $starter,
        $unverifiedAdmin,
        'Attempted unverified administrator plan assignment.',
    ))->toThrow(AuthorizationException::class);

    expect(fn () => $assign->assign(
        $organization,
        $starter,
        $admin,
        'short',
    ))->toThrow(InvalidArgumentException::class);

    $first = $assign->assign(
        organization: $organization,
        plan: $starter,
        actor: $admin,
        reason: 'Approved Starter plan for the pilot workspace.',
        ipAddress: '127.0.0.1',
        userAgent: 'Procura administration test',
    );
    $second = $assign->assign(
        organization: $organization,
        plan: $pro,
        actor: $admin,
        reason: 'Expanded analysis allowance after operational review.',
        ipAddress: '127.0.0.1',
        userAgent: 'Procura administration test',
    );

    expect($first->organization_id)->toBe($organization->getKey())
        ->and($second->fresh()->plan_id)->toBe($pro->getKey())
        ->and(PlatformAuditEvent::query()->count())->toBe(2);

    $event = PlatformAuditEvent::query()->orderByDesc('id')->firstOrFail();

    expect($event->actor_user_id)->toBe($admin->getKey())
        ->and($event->organization_id)->toBe($organization->getKey())
        ->and($event->action)->toBe('organization.plan_assigned')
        ->and($event->old_values['plan_id'])->toBe($starter->getKey())
        ->and($event->new_values['plan_id'])->toBe($pro->getKey())
        ->and($event->reason)->toContain('operational review')
        ->and($event->ip_address)->toBe('127.0.0.1');

    $pro->forceFill(['is_active' => false])->save();

    expect(fn () => $assign->assign(
        $organization,
        $pro,
        $admin,
        'Attempted assignment of an inactive plan version.',
    ))->toThrow(LogicException::class);
});

test('reassigning the same plan is idempotent and does not create a false audit event', function () {
    $this->seed(PlanSeeder::class);

    $organization = Organization::factory()->create();
    $plan = Plan::query()->where('code', 'free')->firstOrFail();
    $admin = superAdmin();
    $assign = app(AssignOrganizationPlan::class);

    $assign->assign($organization, $plan, $admin, 'Initial explicit Free plan assignment.');
    $assign->assign($organization, $plan, $admin, 'Duplicate delivery of the same operation.');

    expect(PlatformAuditEvent::query()->count())->toBe(1);
});

test('bootstraps exactly one verified super administrator with an audit trail', function () {
    $candidate = User::factory()->create([
        'email' => 'first-admin@example.test',
        'email_verified_at' => now(),
    ]);

    $this->artisan('admin:bootstrap-super-admin', [
        'email' => $candidate->email,
        '--reason' => 'Initial production administrator bootstrap.',
    ])->assertSuccessful();

    expect($candidate->refresh()->is_super_admin)->toBeTrue();

    $event = PlatformAuditEvent::query()->sole();

    expect($event->actor_user_id)->toBe($candidate->id)
        ->and($event->action)->toBe('user.super_admin_bootstrapped')
        ->and($event->subject_type)->toBe($candidate->getMorphClass())
        ->and($event->subject_id)->toBe((string) $candidate->id)
        ->and($event->old_values)->toBe(['is_super_admin' => false])
        ->and($event->new_values)->toBe(['is_super_admin' => true])
        ->and($event->reason)->toBe('Initial production administrator bootstrap.');

    $secondCandidate = User::factory()->create(['email_verified_at' => now()]);

    $this->artisan('admin:bootstrap-super-admin', [
        'email' => $secondCandidate->email,
        '--reason' => 'Attempted second administrator bootstrap.',
    ])->assertFailed();

    expect($secondCandidate->refresh()->is_super_admin)->toBeFalse()
        ->and(PlatformAuditEvent::query()->count())->toBe(1);
});

test('rejects an unverified or unexplained super administrator bootstrap', function () {
    $unverified = User::factory()->unverified()->create();

    expect(fn () => app(BootstrapSuperAdmin::class)->execute(
        $unverified,
        'Initial production administrator bootstrap.',
    ))->toThrow(LogicException::class);

    $verified = User::factory()->create(['email_verified_at' => now()]);

    expect(fn () => app(BootstrapSuperAdmin::class)->execute($verified, 'short'))
        ->toThrow(InvalidArgumentException::class);

    expect(User::query()->where('is_super_admin', true)->exists())->toBeFalse()
        ->and(PlatformAuditEvent::query()->exists())->toBeFalse();
});
