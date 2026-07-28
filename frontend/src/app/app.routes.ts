import { Routes } from '@angular/router';

import { authGuard, guestGuard, verifiedGuard } from './core/auth/auth.guard';

export const routes: Routes = [
  {
    path: '',
    data: { titleKey: 'route.landing' },
    loadComponent: () =>
      import('./features/landing/landing.page').then((component) => component.LandingPage),
  },
  {
    path: 'login',
    canMatch: [guestGuard],
    data: { titleKey: 'route.login' },
    loadComponent: () =>
      import('./features/auth/login/login.page').then((component) => component.LoginPage),
  },
  {
    path: 'register',
    canMatch: [guestGuard],
    data: { titleKey: 'route.register' },
    loadComponent: () =>
      import('./features/auth/register/register.page').then(
        (component) => component.RegisterPage,
      ),
  },
  {
    path: 'forgot-password',
    canMatch: [guestGuard],
    data: { titleKey: 'route.forgotPassword' },
    loadComponent: () =>
      import('./features/auth/forgot-password/forgot-password.page').then(
        (component) => component.ForgotPasswordPage,
      ),
  },
  {
    path: 'reset-password',
    canMatch: [guestGuard],
    data: { titleKey: 'route.resetPassword' },
    loadComponent: () =>
      import('./features/auth/reset-password/reset-password.page').then(
        (component) => component.ResetPasswordPage,
      ),
  },
  {
    path: 'verify-email',
    canMatch: [authGuard],
    data: { titleKey: 'route.verifyEmail' },
    loadComponent: () =>
      import('./features/auth/verify-email/verify-email.page').then(
        (component) => component.VerifyEmailPage,
      ),
  },
  {
    path: 'confirm-password',
    canMatch: [verifiedGuard],
    data: { titleKey: 'route.confirmPassword' },
    loadComponent: () =>
      import('./features/auth/confirm-password/confirm-password.page').then(
        (component) => component.ConfirmPasswordPage,
      ),
  },
  {
    path: 'workspace-unavailable',
    data: { titleKey: 'route.workspaceUnavailable' },
    loadComponent: () =>
      import('./features/workspace-unavailable/workspace-unavailable.page').then(
        (component) => component.WorkspaceUnavailablePage,
      ),
  },
  {
    path: 'invitations/accept',
    canMatch: [verifiedGuard],
    data: { titleKey: 'route.acceptInvitation' },
    loadComponent: () =>
      import('./features/invitation-accept/invitation-accept.page').then(
        (component) => component.InvitationAcceptPage,
      ),
  },
  {
    path: 'app',
    canMatch: [verifiedGuard],
    loadComponent: () =>
      import('./layout/app-shell/app-shell').then((component) => component.AppShell),
    children: [
      {
        path: '',
        pathMatch: 'full',
        redirectTo: 'overview',
      },
      {
        path: 'overview',
        data: { titleKey: 'route.overview' },
        loadComponent: () =>
          import('./features/dashboard/dashboard.page').then(
            (component) => component.DashboardPage,
          ),
      },
      {
        path: 'organization',
        data: { titleKey: 'route.organization' },
        loadComponent: () =>
          import('./features/organization-management/organization-management.page').then(
            (component) => component.OrganizationManagementPage,
          ),
      },
      {
        path: 'buy',
        data: { titleKey: 'route.buyListings' },
        loadComponent: () =>
          import('./features/buy-listings/list/listing-list.page').then(
            (component) => component.ListingListPage,
          ),
      },
      {
        path: 'buy/new',
        data: { titleKey: 'route.addSourceListing' },
        loadComponent: () =>
          import('./features/buy-listings/intake/listing-intake.page').then(
            (component) => component.ListingIntakePage,
          ),
      },
      {
        path: 'buy/imports',
        data: { titleKey: 'route.marketplaceImports' },
        loadComponent: () =>
          import('./features/buy-listings/intake/marketplace-import.page').then(
            (component) => component.MarketplaceImportPage,
          ),
      },
      {
        path: 'buy/:listingId/analysis/:analysisId',
        data: { titleKey: 'route.buyAnalysis' },
        loadComponent: () =>
          import('./features/buy-analyses/detail/analysis-detail.page').then(
            (component) => component.AnalysisDetailPage,
          ),
      },
      {
        path: 'buy/:id',
        data: { titleKey: 'route.listingDetail' },
        loadComponent: () =>
          import('./features/buy-listings/detail/listing-detail.page').then(
            (component) => component.ListingDetailPage,
          ),
      },
      {
        path: 'sell',
        data: { titleKey: 'route.ownedProducts' },
        loadComponent: () =>
          import('./features/sell-products/list/owned-product-list.page').then(
            (component) => component.OwnedProductListPage,
          ),
      },
      {
        path: 'sell/new',
        data: { titleKey: 'route.addOwnedProduct' },
        loadComponent: () =>
          import('./features/sell-products/intake/owned-product-intake.page').then(
            (component) => component.OwnedProductIntakePage,
          ),
      },
      {
        path: 'sell/:id',
        data: { titleKey: 'route.ownedProductDetail' },
        loadComponent: () =>
          import('./features/sell-products/detail/owned-product-detail.page').then(
            (component) => component.OwnedProductDetailPage,
          ),
      },
      {
        path: 'saved-searches',
        data: { titleKey: 'route.savedSearches' },
        loadComponent: () =>
          import('./features/saved-searches/list/saved-search-list.page').then(
            (component) => component.SavedSearchListPage,
          ),
      },
      {
        path: 'saved-searches/new',
        data: { titleKey: 'route.createSavedSearch' },
        loadComponent: () =>
          import('./features/saved-searches/form/saved-search-form.page').then(
            (component) => component.SavedSearchFormPage,
          ),
      },
      {
        path: 'saved-searches/:id/edit',
        data: { titleKey: 'route.editSavedSearch' },
        loadComponent: () =>
          import('./features/saved-searches/form/saved-search-form.page').then(
            (component) => component.SavedSearchFormPage,
          ),
      },
      {
        path: 'saved-searches/:id',
        data: { titleKey: 'route.savedSearchDetail' },
        loadComponent: () =>
          import('./features/saved-searches/detail/saved-search-detail.page').then(
            (component) => component.SavedSearchDetailPage,
          ),
      },
      {
        path: 'notifications',
        data: { titleKey: 'route.notifications' },
        loadComponent: () =>
          import('./features/notifications/notification-list.page').then(
            (component) => component.NotificationListPage,
          ),
      },
      {
        path: 'markets',
        data: { titleKey: 'route.markets' },
        loadComponent: () =>
          import('./features/markets/markets.page').then(
            (component) => component.MarketsPage,
          ),
      },
      {
        path: 'subscription',
        data: { titleKey: 'route.subscription' },
        loadComponent: () =>
          import('./features/subscription/subscription.page').then(
            (component) => component.SubscriptionPage,
          ),
      },
      {
        path: 'privacy',
        data: { titleKey: 'route.privacy' },
        loadComponent: () =>
          import('./features/privacy/privacy.page').then(
            (component) => component.PrivacyPage,
          ),
      },
    ],
  },
  {
    path: '**',
    redirectTo: '',
  },
];
