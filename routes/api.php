<?php

use App\Http\Controllers\Api\V1\Analyses\AnalysisIndexController;
use App\Http\Controllers\Api\V1\Analyses\ConfirmAnalysisCostsController;
use App\Http\Controllers\Api\V1\Analyses\ConfirmOpportunityEvidenceController;
use App\Http\Controllers\Api\V1\Analyses\RecordBuyerDecisionController;
use App\Http\Controllers\Api\V1\Analyses\ShowAnalysisController;
use App\Http\Controllers\Api\V1\Analyses\StoreBuyAnalysisController;
use App\Http\Controllers\Api\V1\Analyses\SubmitAnalysisController;
use App\Http\Controllers\Api\V1\ArchiveSavedSearchController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\AuthenticatedUserController;
use App\Http\Controllers\Api\V1\BeginTelegramConnectionController;
use App\Http\Controllers\Api\V1\Comparables\IndexAnalysisComparableController;
use App\Http\Controllers\Api\V1\Comparables\StoreAnalysisComparableController;
use App\Http\Controllers\Api\V1\Comparables\StoreComparableMarketNormalizationController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\Listings\DeleteListingImageController;
use App\Http\Controllers\Api\V1\Listings\ListingIndexController;
use App\Http\Controllers\Api\V1\Listings\MarketplaceSourceIndexController;
use App\Http\Controllers\Api\V1\Listings\ShowListingController;
use App\Http\Controllers\Api\V1\Listings\ShowListingImageController;
use App\Http\Controllers\Api\V1\Listings\StoreListingController;
use App\Http\Controllers\Api\V1\Listings\StoreListingImagesController;
use App\Http\Controllers\Api\V1\Listings\UpdateListingController;
use App\Http\Controllers\Api\V1\MarketplaceImportIndexController;
use App\Http\Controllers\Api\V1\MarketReferenceController;
use App\Http\Controllers\Api\V1\NotificationIndexController;
use App\Http\Controllers\Api\V1\Organizations\AcceptOrganizationInvitationController;
use App\Http\Controllers\Api\V1\Organizations\ActivateOrganizationController;
use App\Http\Controllers\Api\V1\Organizations\CreateOrganizationController;
use App\Http\Controllers\Api\V1\Organizations\InviteOrganizationMemberController;
use App\Http\Controllers\Api\V1\Organizations\OpenBillingPortalController;
use App\Http\Controllers\Api\V1\Organizations\OrganizationIndexController;
use App\Http\Controllers\Api\V1\Organizations\OrganizationManagementController;
use App\Http\Controllers\Api\V1\Organizations\OrganizationMarketPreferencesController;
use App\Http\Controllers\Api\V1\Organizations\OrganizationSubscriptionController;
use App\Http\Controllers\Api\V1\Organizations\RemoveOrganizationMemberController;
use App\Http\Controllers\Api\V1\Organizations\RevokeOrganizationInvitationController;
use App\Http\Controllers\Api\V1\Organizations\StartBillingCheckoutController;
use App\Http\Controllers\Api\V1\Organizations\TransferOrganizationOwnershipController;
use App\Http\Controllers\Api\V1\Organizations\UpdateOrganizationController;
use App\Http\Controllers\Api\V1\Organizations\UpdateOrganizationMemberRoleController;
use App\Http\Controllers\Api\V1\OwnedProducts\AssessOwnedProductController;
use App\Http\Controllers\Api\V1\OwnedProducts\DeleteOwnedProductImageController;
use App\Http\Controllers\Api\V1\OwnedProducts\IndexEstimateAccuracyCandidateController;
use App\Http\Controllers\Api\V1\OwnedProducts\OwnedProductIndexController;
use App\Http\Controllers\Api\V1\OwnedProducts\ShowOutcomeTrackingController;
use App\Http\Controllers\Api\V1\OwnedProducts\ShowOwnedProductController;
use App\Http\Controllers\Api\V1\OwnedProducts\ShowOwnedProductImageController;
use App\Http\Controllers\Api\V1\OwnedProducts\ShowSalePortfolioController;
use App\Http\Controllers\Api\V1\OwnedProducts\ShowSellListingDraftsController;
use App\Http\Controllers\Api\V1\OwnedProducts\ShowSellPriceIntelligenceController;
use App\Http\Controllers\Api\V1\OwnedProducts\StoreActualCostSnapshotController;
use App\Http\Controllers\Api\V1\OwnedProducts\StoreActualPurchaseController;
use App\Http\Controllers\Api\V1\OwnedProducts\StoreActualSaleController;
use App\Http\Controllers\Api\V1\OwnedProducts\StoreEstimateAccuracyAttributionController;
use App\Http\Controllers\Api\V1\OwnedProducts\StoreOwnedProductController;
use App\Http\Controllers\Api\V1\OwnedProducts\StoreOwnedProductImagesController;
use App\Http\Controllers\Api\V1\OwnedProducts\StoreSalePortfolioEntryController;
use App\Http\Controllers\Api\V1\OwnedProducts\StoreSalePortfolioEventController;
use App\Http\Controllers\Api\V1\OwnedProducts\StoreSellComparableController;
use App\Http\Controllers\Api\V1\OwnedProducts\StoreSellComparableMarketNormalizationController;
use App\Http\Controllers\Api\V1\OwnedProducts\StoreSellListingDraftController;
use App\Http\Controllers\Api\V1\OwnedProducts\UpdateOwnedProductController;
use App\Http\Controllers\Api\V1\Privacy\CancelPrivacyRequestController;
use App\Http\Controllers\Api\V1\Privacy\PrivacyRequestIndexController;
use App\Http\Controllers\Api\V1\Privacy\StorePrivacyRequestController;
use App\Http\Controllers\Api\V1\Products\ProductCategoryIndexController;
use App\Http\Controllers\Api\V1\Products\SearchProductController;
use App\Http\Controllers\Api\V1\Products\ShowProductController;
use App\Http\Controllers\Api\V1\RecordNotificationStateController;
use App\Http\Controllers\Api\V1\RevokeTelegramConnectionController;
use App\Http\Controllers\Api\V1\SavedSearchIndexController;
use App\Http\Controllers\Api\V1\SavedSearchMatchIndexController;
use App\Http\Controllers\Api\V1\ShowMarketplaceImportController;
use App\Http\Controllers\Api\V1\ShowSavedSearchController;
use App\Http\Controllers\Api\V1\ShowTelegramConnectionController;
use App\Http\Controllers\Api\V1\StoreMarketplaceImportController;
use App\Http\Controllers\Api\V1\StoreSavedSearchController;
use App\Http\Controllers\Api\V1\TelegramWebhookController;
use App\Http\Controllers\Api\V1\UpdateSavedSearchController;
use App\Http\Controllers\Api\V1\Users\UpdatePreferredLocaleController;
use App\Http\Middleware\VerifyTelegramWebhookSecret;
use Illuminate\Support\Facades\Route;
use Laravel\Cashier\Http\Controllers\WebhookController as CashierWebhookController;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;
use Laravel\Fortify\Http\Controllers\ConfirmablePasswordController;
use Laravel\Fortify\Http\Controllers\ConfirmedPasswordStatusController;
use Laravel\Fortify\Http\Controllers\EmailVerificationNotificationController;
use Laravel\Fortify\Http\Controllers\NewPasswordController;
use Laravel\Fortify\Http\Controllers\PasswordResetLinkController;
use Laravel\Fortify\Http\Controllers\RegisteredUserController;
use Laravel\Fortify\Http\Controllers\VerifyEmailController;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', HealthController::class)
        ->middleware('throttle:api')
        ->name('api.v1.health');

    Route::get('/reference/markets', MarketReferenceController::class)
        ->middleware('throttle:api')
        ->name('api.v1.reference.markets');

    Route::post(
        '/integrations/telegram/webhook',
        TelegramWebhookController::class,
    )
        ->middleware([
            VerifyTelegramWebhookSecret::class,
            'throttle:telegram-webhook',
        ])
        ->name('api.v1.integrations.telegram.webhook');

    Route::post(
        '/integrations/stripe/webhook',
        [CashierWebhookController::class, 'handleWebhook'],
    )
        ->middleware([
            'stripe-webhook-configured',
            'throttle:stripe-webhook',
        ])
        ->name('api.v1.integrations.stripe.webhook');

    Route::prefix('auth')->group(function (): void {
        Route::post('/login', [AuthenticatedSessionController::class, 'store'])
            ->middleware(['guest:web', 'throttle:login'])
            ->name('api.v1.auth.login');

        Route::post('/register', [RegisteredUserController::class, 'store'])
            ->middleware(['guest:web', 'throttle:6,1'])
            ->name('api.v1.auth.register');

        Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
            ->middleware(['guest:web', 'throttle:6,1'])
            ->name('api.v1.auth.password.email');

        Route::post('/reset-password', [NewPasswordController::class, 'store'])
            ->middleware(['guest:web', 'throttle:6,1'])
            ->name('api.v1.auth.password.update');

        Route::post('/logout', LogoutController::class)
            ->middleware('auth:sanctum')
            ->name('api.v1.auth.logout');

        Route::get('/email/verify/{id}/{hash}', VerifyEmailController::class)
            ->middleware(['auth:sanctum', 'signed:relative', 'throttle:6,1'])
            ->name('api.v1.auth.email.verify');

        Route::post(
            '/email/verification-notification',
            [EmailVerificationNotificationController::class, 'store'],
        )
            ->middleware(['auth:sanctum', 'throttle:6,1'])
            ->name('api.v1.auth.email.verification.send');

        Route::get(
            '/password-confirmation',
            [ConfirmedPasswordStatusController::class, 'show'],
        )
            ->middleware('auth:sanctum')
            ->name('api.v1.auth.password.confirmation');

        Route::post(
            '/confirm-password',
            [ConfirmablePasswordController::class, 'store'],
        )
            ->middleware(['auth:sanctum', 'throttle:6,1'])
            ->name('api.v1.auth.password.confirm');
    });

    Route::post('/organization-invitations/accept', AcceptOrganizationInvitationController::class)
        ->middleware(['auth:sanctum', 'verified', 'throttle:api'])
        ->name('api.v1.organization-invitations.accept');

    Route::get('/me', AuthenticatedUserController::class)
        ->middleware(['auth:sanctum', 'throttle:api', 'organization.context'])
        ->name('api.v1.me');

    Route::patch('/me/preferences', UpdatePreferredLocaleController::class)
        ->middleware(['auth:sanctum', 'throttle:api'])
        ->name('api.v1.me.preferences.update');

    Route::middleware([
        'auth:sanctum',
        'verified',
        'throttle:privacy',
    ])->group(function (): void {
        Route::get(
            '/me/privacy-requests',
            PrivacyRequestIndexController::class,
        )->name('api.v1.me.privacy-requests.index');
        Route::post(
            '/me/privacy-requests',
            StorePrivacyRequestController::class,
        )->name('api.v1.me.privacy-requests.store');
        Route::post(
            '/me/privacy-requests/{privacyRequest}/cancel',
            CancelPrivacyRequestController::class,
        )->name('api.v1.me.privacy-requests.cancel');
    });

    Route::middleware(['auth:sanctum', 'verified', 'throttle:api', 'organization.context'])
        ->group(function (): void {
            Route::get('/organizations', OrganizationIndexController::class)
                ->name('api.v1.organizations.index');

            Route::post('/organizations', CreateOrganizationController::class)
                ->name('api.v1.organizations.store');

            Route::put('/organizations/{organization}/activate', ActivateOrganizationController::class)
                ->name('api.v1.organizations.activate');

            Route::get('/organization/management', OrganizationManagementController::class)
                ->name('api.v1.organization.management');

            Route::patch('/organization', UpdateOrganizationController::class)
                ->name('api.v1.organization.update');

            Route::get(
                '/organization/market-preferences',
                [OrganizationMarketPreferencesController::class, 'show'],
            )->name('api.v1.organization.market-preferences.show');

            Route::put(
                '/organization/market-preferences',
                [OrganizationMarketPreferencesController::class, 'update'],
            )->name('api.v1.organization.market-preferences.update');

            Route::get('/organization/subscription', OrganizationSubscriptionController::class)
                ->name('api.v1.organization.subscription.show');

            Route::post(
                '/organization/billing/checkout',
                StartBillingCheckoutController::class,
            )
                ->middleware('throttle:billing')
                ->name('api.v1.organization.billing.checkout');

            Route::post(
                '/organization/billing/portal',
                OpenBillingPortalController::class,
            )
                ->middleware('throttle:billing')
                ->name('api.v1.organization.billing.portal');

            Route::get('/marketplace-sources', MarketplaceSourceIndexController::class)
                ->name('api.v1.marketplace-sources.index');

            Route::get('/marketplace-imports', MarketplaceImportIndexController::class)
                ->name('api.v1.marketplace-imports.index');

            Route::post('/marketplace-imports', StoreMarketplaceImportController::class)
                ->middleware('throttle:uploads')
                ->name('api.v1.marketplace-imports.store');

            Route::get(
                '/marketplace-imports/{marketplaceImport}',
                ShowMarketplaceImportController::class,
            )->name('api.v1.marketplace-imports.show');

            Route::get('/listings', ListingIndexController::class)
                ->name('api.v1.listings.index');

            Route::post('/listings', StoreListingController::class)
                ->name('api.v1.listings.store');

            Route::get('/listings/{listing}', ShowListingController::class)
                ->name('api.v1.listings.show');

            Route::patch('/listings/{listing}', UpdateListingController::class)
                ->name('api.v1.listings.update');

            Route::post('/listings/{listing}/images', StoreListingImagesController::class)
                ->middleware('throttle:uploads')
                ->name('api.v1.listings.images.store');

            Route::delete(
                '/listings/{listing}/images/{image}',
                DeleteListingImageController::class,
            )->name('api.v1.listings.images.destroy');

            Route::get('/listing-images/{image}/content', ShowListingImageController::class)
                ->middleware('signed:relative')
                ->name('api.v1.listing-images.content');

            Route::get('/saved-searches', SavedSearchIndexController::class)
                ->name('api.v1.saved-searches.index');

            Route::post('/saved-searches', StoreSavedSearchController::class)
                ->name('api.v1.saved-searches.store');

            Route::get('/saved-searches/{savedSearch}', ShowSavedSearchController::class)
                ->name('api.v1.saved-searches.show');

            Route::put('/saved-searches/{savedSearch}', UpdateSavedSearchController::class)
                ->name('api.v1.saved-searches.update');

            Route::post(
                '/saved-searches/{savedSearch}/archive',
                ArchiveSavedSearchController::class,
            )->name('api.v1.saved-searches.archive');

            Route::get(
                '/saved-searches/{savedSearch}/matches',
                SavedSearchMatchIndexController::class,
            )->name('api.v1.saved-searches.matches.index');

            Route::get('/notifications', NotificationIndexController::class)
                ->name('api.v1.notifications.index');

            Route::get(
                '/me/telegram-connection',
                ShowTelegramConnectionController::class,
            )->name('api.v1.me.telegram-connection.show');

            Route::post(
                '/me/telegram-connection/link',
                BeginTelegramConnectionController::class,
            )
                ->middleware('throttle:telegram-link')
                ->name('api.v1.me.telegram-connection.link');

            Route::delete(
                '/me/telegram-connection',
                RevokeTelegramConnectionController::class,
            )
                ->middleware('throttle:telegram-link')
                ->name('api.v1.me.telegram-connection.destroy');

            Route::post(
                '/notifications/{alert}/state',
                RecordNotificationStateController::class,
            )->name('api.v1.notifications.state.store');

            Route::get('/owned-products', OwnedProductIndexController::class)
                ->name('api.v1.owned-products.index');

            Route::post('/owned-products', StoreOwnedProductController::class)
                ->name('api.v1.owned-products.store');

            Route::get('/owned-products/{ownedProduct}', ShowOwnedProductController::class)
                ->name('api.v1.owned-products.show');

            Route::patch('/owned-products/{ownedProduct}', UpdateOwnedProductController::class)
                ->name('api.v1.owned-products.update');

            Route::post(
                '/owned-products/{ownedProduct}/assessments',
                AssessOwnedProductController::class,
            )->name('api.v1.owned-products.assessments.store');

            Route::get(
                '/owned-products/{ownedProduct}/sell-intelligence',
                ShowSellPriceIntelligenceController::class,
            )->name('api.v1.owned-products.sell-intelligence.show');

            Route::post(
                '/owned-products/{ownedProduct}/comparables',
                StoreSellComparableController::class,
            )->name('api.v1.owned-products.comparables.store');

            Route::post(
                '/owned-products/{ownedProduct}/comparables/{comparable}/market-normalizations',
                StoreSellComparableMarketNormalizationController::class,
            )->name('api.v1.owned-products.comparables.market-normalizations.store');

            Route::get(
                '/owned-products/{ownedProduct}/listing-drafts',
                ShowSellListingDraftsController::class,
            )->name('api.v1.owned-products.listing-drafts.index');

            Route::post(
                '/owned-products/{ownedProduct}/listing-drafts',
                StoreSellListingDraftController::class,
            )->name('api.v1.owned-products.listing-drafts.store');

            Route::get(
                '/owned-products/{ownedProduct}/sale-portfolio',
                ShowSalePortfolioController::class,
            )->name('api.v1.owned-products.sale-portfolio.show');

            Route::post(
                '/owned-products/{ownedProduct}/sale-portfolio',
                StoreSalePortfolioEntryController::class,
            )->name('api.v1.owned-products.sale-portfolio.store');

            Route::post(
                '/owned-products/{ownedProduct}/sale-portfolio/{salePortfolioEntry}/events',
                StoreSalePortfolioEventController::class,
            )->name('api.v1.owned-products.sale-portfolio.events.store');

            Route::get(
                '/owned-products/{ownedProduct}/outcomes',
                ShowOutcomeTrackingController::class,
            )->name('api.v1.owned-products.outcomes.show');

            Route::get(
                '/owned-products/{ownedProduct}/outcomes/estimate-candidates',
                IndexEstimateAccuracyCandidateController::class,
            )->name('api.v1.owned-products.outcomes.estimate-candidates.index');

            Route::post(
                '/owned-products/{ownedProduct}/outcomes/estimate-attributions',
                StoreEstimateAccuracyAttributionController::class,
            )->name('api.v1.owned-products.outcomes.estimate-attributions.store');

            Route::post(
                '/owned-products/{ownedProduct}/outcomes/purchases',
                StoreActualPurchaseController::class,
            )->name('api.v1.owned-products.outcomes.purchases.store');

            Route::post(
                '/owned-products/{ownedProduct}/outcomes/cost-snapshots',
                StoreActualCostSnapshotController::class,
            )->name('api.v1.owned-products.outcomes.cost-snapshots.store');

            Route::post(
                '/owned-products/{ownedProduct}/sale-portfolio/{salePortfolioEntry}/outcomes',
                StoreActualSaleController::class,
            )->name('api.v1.owned-products.outcomes.sales.store');

            Route::post(
                '/owned-products/{ownedProduct}/images',
                StoreOwnedProductImagesController::class,
            )
                ->middleware('throttle:uploads')
                ->name('api.v1.owned-products.images.store');

            Route::delete(
                '/owned-products/{ownedProduct}/images/{image}',
                DeleteOwnedProductImageController::class,
            )->name('api.v1.owned-products.images.destroy');

            Route::get(
                '/owned-product-images/{image}/content',
                ShowOwnedProductImageController::class,
            )
                ->middleware('signed:relative')
                ->name('api.v1.owned-product-images.content');

            Route::get('/analyses', AnalysisIndexController::class)
                ->name('api.v1.analyses.index');

            Route::post('/buy-analyses', StoreBuyAnalysisController::class)
                ->name('api.v1.buy-analyses.store');

            Route::get('/analyses/{analysis}', ShowAnalysisController::class)
                ->name('api.v1.analyses.show');

            Route::post('/analyses/{analysis}/submit', SubmitAnalysisController::class)
                ->name('api.v1.analyses.submit');

            Route::post(
                '/analyses/{analysis}/costs',
                ConfirmAnalysisCostsController::class,
            )->name('api.v1.analyses.costs.store');

            Route::post(
                '/analyses/{analysis}/opportunity-evidence',
                ConfirmOpportunityEvidenceController::class,
            )->name('api.v1.analyses.opportunity-evidence.store');

            Route::post(
                '/analyses/{analysis}/buyer-decisions',
                RecordBuyerDecisionController::class,
            )->name('api.v1.analyses.buyer-decisions.store');

            Route::get(
                '/analyses/{analysis}/comparables',
                IndexAnalysisComparableController::class,
            )->name('api.v1.analyses.comparables.index');

            Route::post(
                '/analyses/{analysis}/comparables',
                StoreAnalysisComparableController::class,
            )->name('api.v1.analyses.comparables.store');

            Route::post(
                '/analyses/{analysis}/comparables/{comparable}/market-normalizations',
                StoreComparableMarketNormalizationController::class,
            )->name('api.v1.analyses.comparables.market-normalizations.store');

            Route::get('/products/search', SearchProductController::class)
                ->name('api.v1.products.search');

            Route::get('/product-categories', ProductCategoryIndexController::class)
                ->name('api.v1.product-categories.index');

            Route::get('/products/{productModel}', ShowProductController::class)
                ->name('api.v1.products.show');

            Route::post('/organization/invitations', InviteOrganizationMemberController::class)
                ->name('api.v1.organization.invitations.store');

            Route::delete(
                '/organization/invitations/{invitation}',
                RevokeOrganizationInvitationController::class,
            )->name('api.v1.organization.invitations.destroy');

            Route::patch(
                '/organization/members/{membership}',
                UpdateOrganizationMemberRoleController::class,
            )->name('api.v1.organization.members.update');

            Route::delete(
                '/organization/members/{membership}',
                RemoveOrganizationMemberController::class,
            )->name('api.v1.organization.members.destroy');

            Route::post(
                '/organization/members/{membership}/transfer-ownership',
                TransferOrganizationOwnershipController::class,
            )->name('api.v1.organization.ownership.transfer');
        });
});
