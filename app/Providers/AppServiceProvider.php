<?php

namespace App\Providers;

use App\Analysis\Contracts\ListingAiAnalyzer;
use App\Analysis\Providers\FakeListingAiAnalyzer;
use App\Billing\Contracts\BillingProvider;
use App\Billing\Providers\StripeBillingProvider;
use App\ComparableSelection\Contracts\ComparableSelector;
use App\ComparableSelection\Selectors\DeterministicComparableSelector;
use App\DealScoring\Contracts\DealScoreEvaluator;
use App\DealScoring\Evaluators\DeterministicDealScoreEvaluator;
use App\EstimateAccuracy\Calculators\DeterministicEstimateAccuracyCalculator;
use App\EstimateAccuracy\Contracts\EstimateAccuracyCalculator;
use App\Models\Analysis;
use App\Models\ComparableMarketNormalization;
use App\Models\Listing;
use App\Models\MarketplaceImport;
use App\Models\Organization;
use App\Models\OwnedProduct;
use App\Models\PrivacyRequest;
use App\Models\SavedSearch;
use App\Models\SellComparableMarketNormalization;
use App\Monitoring\Telegram\Contracts\TelegramProvider;
use App\Monitoring\Telegram\Providers\TelegramBotApiProvider;
use App\OpportunityAssessment\Contracts\DemandEvaluator;
use App\OpportunityAssessment\Contracts\LogisticsEvaluator;
use App\OpportunityAssessment\Evaluators\DeterministicDemandEvaluator;
use App\OpportunityAssessment\Evaluators\DeterministicLogisticsEvaluator;
use App\OwnedProductAssessment\Contracts\OwnedProductAssessor;
use App\OwnedProductAssessment\Evaluators\DeterministicOwnedProductAssessor;
use App\Policies\AnalysisPolicy;
use App\Policies\ComparableMarketNormalizationPolicy;
use App\Policies\ListingPolicy;
use App\Policies\MarketplaceImportPolicy;
use App\Policies\OrganizationPolicy;
use App\Policies\OwnedProductPolicy;
use App\Policies\PrivacyRequestPolicy;
use App\Policies\SavedSearchPolicy;
use App\Policies\SellComparableMarketNormalizationPolicy;
use App\Pricing\Contracts\ExchangeRateResolver;
use App\Pricing\Contracts\PriceEstimator;
use App\Pricing\Estimators\DeterministicPriceEstimator;
use App\Pricing\Resolvers\DatedExchangeRateResolver;
use App\ProductMatching\Contracts\ProductMatcher;
use App\ProductMatching\Providers\FakeCatalogProductMatcher;
use App\ProfitCalculation\Calculators\DeterministicProfitCalculator;
use App\ProfitCalculation\Contracts\ProfitCalculator;
use App\RiskAssessment\Contracts\RiskEvaluator;
use App\RiskAssessment\Evaluators\DeterministicRiskEvaluator;
use App\Tenancy\OrganizationContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        Cashier::ignoreRoutes();
        Cashier::useCustomerModel(Organization::class);

        $this->app->bind(
            BillingProvider::class,
            function (): BillingProvider {
                return match (config('billing.provider')) {
                    'stripe' => $this->app->make(StripeBillingProvider::class),
                    default => throw new \LogicException(
                        'The configured billing provider is not supported.',
                    ),
                };
            },
        );

        $this->app->bind(ListingAiAnalyzer::class, function (): ListingAiAnalyzer {
            return match (config('analyses.provider')) {
                'fake' => new FakeListingAiAnalyzer,
                default => throw new \LogicException('The configured analysis provider is not supported.'),
            };
        });

        $this->app->bind(ProductMatcher::class, function (): ProductMatcher {
            return match (config('product_matching.provider')) {
                'fake' => new FakeCatalogProductMatcher,
                default => throw new \LogicException(
                    'The configured product matching provider is not supported.',
                ),
            };
        });

        $this->app->bind(
            OwnedProductAssessor::class,
            function (): OwnedProductAssessor {
                return match (config('owned_product_assessment.provider')) {
                    'deterministic' => $this->app->make(
                        DeterministicOwnedProductAssessor::class,
                    ),
                    default => throw new \LogicException(
                        'The configured owned-product assessment provider is not supported.',
                    ),
                };
            },
        );

        $this->app->bind(
            ComparableSelector::class,
            DeterministicComparableSelector::class,
        );
        $this->app->bind(
            ExchangeRateResolver::class,
            DatedExchangeRateResolver::class,
        );
        $this->app->bind(
            PriceEstimator::class,
            DeterministicPriceEstimator::class,
        );
        $this->app->bind(
            RiskEvaluator::class,
            DeterministicRiskEvaluator::class,
        );
        $this->app->bind(
            ProfitCalculator::class,
            DeterministicProfitCalculator::class,
        );
        $this->app->bind(
            LogisticsEvaluator::class,
            DeterministicLogisticsEvaluator::class,
        );
        $this->app->bind(
            DemandEvaluator::class,
            DeterministicDemandEvaluator::class,
        );
        $this->app->bind(
            DealScoreEvaluator::class,
            DeterministicDealScoreEvaluator::class,
        );
        $this->app->bind(
            EstimateAccuracyCalculator::class,
            DeterministicEstimateAccuracyCalculator::class,
        );
        $this->app->bind(
            TelegramProvider::class,
            function (): TelegramProvider {
                return match (config('monitoring.telegram.provider')) {
                    'telegram-bot-api' => $this->app->make(
                        TelegramBotApiProvider::class,
                    ),
                    default => throw new \LogicException(
                        'The configured Telegram provider is not supported.',
                    ),
                };
            },
        );

        $this->app->scoped(
            OrganizationContext::class,
            fn (): OrganizationContext => new OrganizationContext,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Analysis::class, AnalysisPolicy::class);
        Gate::policy(
            ComparableMarketNormalization::class,
            ComparableMarketNormalizationPolicy::class,
        );
        Gate::policy(Listing::class, ListingPolicy::class);
        Gate::policy(MarketplaceImport::class, MarketplaceImportPolicy::class);
        Gate::policy(Organization::class, OrganizationPolicy::class);
        Gate::policy(OwnedProduct::class, OwnedProductPolicy::class);
        Gate::policy(PrivacyRequest::class, PrivacyRequestPolicy::class);
        Gate::policy(SavedSearch::class, SavedSearchPolicy::class);
        Gate::policy(
            SellComparableMarketNormalization::class,
            SellComparableMarketNormalizationPolicy::class,
        );

        RateLimiter::for('api', function (Request $request): Limit {
            return Limit::perMinute(60)
                ->by($request->user()?->getAuthIdentifier() ?? $request->ip());
        });

        RateLimiter::for('uploads', function (Request $request): Limit {
            return Limit::perMinute(20)
                ->by($request->user()?->getAuthIdentifier() ?? $request->ip());
        });

        RateLimiter::for('telegram-link', function (Request $request): Limit {
            return Limit::perMinute(5)
                ->by($request->user()?->getAuthIdentifier() ?? $request->ip());
        });

        RateLimiter::for(
            'telegram-webhook',
            static fn (Request $request): Limit => Limit::perMinute(6000)
                ->by($request->ip()),
        );

        RateLimiter::for('billing', function (Request $request): Limit {
            return Limit::perMinute(5)
                ->by($request->user()?->getAuthIdentifier() ?? $request->ip());
        });

        RateLimiter::for('privacy', function (Request $request): Limit {
            return Limit::perMinute(10)
                ->by($request->user()?->getAuthIdentifier() ?? $request->ip());
        });

        RateLimiter::for(
            'stripe-webhook',
            static fn (Request $request): Limit => Limit::perMinute(600)
                ->by($request->ip()),
        );

    }
}
