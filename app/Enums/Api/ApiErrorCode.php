<?php

namespace App\Enums\Api;

enum ApiErrorCode: string
{
    case BillingCheckoutExpired = 'billing_checkout_expired';
    case BillingManualAssignment = 'billing_manual_assignment';
    case BillingNotConfigured = 'billing_not_configured';
    case BillingOperationInProgress = 'billing_operation_in_progress';
    case BillingPortalUnavailable = 'billing_portal_unavailable';
    case BillingProviderInvalidResponse = 'billing_provider_invalid_response';
    case BillingProviderUnavailable = 'billing_provider_unavailable';
    case BillingSubscriptionExists = 'billing_subscription_exists';
    case BuyerDecisionIdempotencyConflict = 'buyer_decision_idempotency_conflict';
    case BuyerDecisionStaleState = 'buyer_decision_stale_state';
    case MarketplaceImportIdempotencyPayloadMismatch = 'idempotency_payload_mismatch';
    case PrivacyRequestIdempotencyConflict = 'privacy_request_idempotency_conflict';
    case PrivacyRequestStaleState = 'privacy_request_stale_state';
    case SalePortfolioIdempotencyConflict = 'sale_portfolio_idempotency_conflict';
    case SalePortfolioStaleState = 'sale_portfolio_stale_state';
    case ActualCostStaleState = 'actual_cost_stale_state';
    case ActualPurchaseStaleState = 'actual_purchase_stale_state';
    case ActualSaleStalePortfolioState = 'actual_sale_stale_portfolio_state';
    case ActualSaleStaleState = 'actual_sale_stale_state';
    case EstimateAccuracyReportStaleState = 'estimate_accuracy_report_stale_state';
    case EstimateAttributionStaleState = 'estimate_attribution_stale_state';
    case OutcomeIdempotencyConflict = 'outcome_idempotency_conflict';
}
