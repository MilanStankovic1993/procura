<?php

namespace App\Enums\Validation;

enum ApplicationValidationCode: string
{
    case ActivePrivacyRequestExists = 'active_privacy_request_exists';
    case DuplicateImage = 'duplicate_image';
    case EmailNotificationsPlanDisabled = 'email_notifications_plan_disabled';
    case InactiveOutcomeCurrency = 'inactive_outcome_currency';
    case InAppNotificationRequired = 'in_app_notification_required';
    case InvitationAlreadyMember = 'invitation_already_member';
    case InvitationExpired = 'invitation_expired';
    case InvitationPending = 'invitation_pending';
    case InvitationUnavailable = 'invitation_unavailable';
    case InvitationWrongAccount = 'invitation_wrong_account';
    case ListingImageLimit = 'listing_image_limit';
    case ManualAnalysisRetryDisabled = 'manual_analysis_retry_disabled';
    case ManualAnalysisRetryIneligible = 'manual_analysis_retry_ineligible';
    case ManualAnalysisRetryLimit = 'manual_analysis_retry_limit';
    case MissingOutcomeExchangeRate = 'missing_outcome_exchange_rate';
    case NotificationArchived = 'notification_archived';
    case NotificationIdempotencyConflict = 'notification_idempotency_conflict';
    case NotificationStale = 'notification_stale';
    case OwnershipStateInvalid = 'ownership_state_invalid';
    case OwnershipTransferRequired = 'ownership_transfer_required';
    case PrivacyEventHistoryLimit = 'privacy_event_history_limit';
    case PrivacyTransitionNotAllowed = 'privacy_transition_not_allowed';
    case ProductSearchTooShort = 'product_search_too_short';
    case SavedSearchArchived = 'saved_search_archived';
    case SavedSearchBrandModelMismatch = 'saved_search_brand_model_mismatch';
    case SavedSearchCategoryModelMismatch = 'saved_search_category_model_mismatch';
    case SavedSearchStale = 'saved_search_stale';
    case SystemOwnedNotificationEvent = 'system_owned_notification_event';
    case TelegramConnectionRequired = 'telegram_connection_required';
    case TelegramNotConfigured = 'telegram_not_configured';
    case TelegramNotificationsPlanDisabled = 'telegram_notifications_plan_disabled';
    case UnreadableImage = 'unreadable_image';
    case UnsupportedImageType = 'unsupported_image_type';
    case UnsupportedNotificationChannel = 'unsupported_notification_channel';
    case AnalysisComparableManualConnectorRequired = 'analysis_comparable_manual_connector_required';
    case AnalysisComparableProcessingNotFinished = 'analysis_comparable_processing_not_finished';
    case AnalysisComparableProductMatchRequired = 'analysis_comparable_product_match_required';
    case AnalysisComparableVariantMismatch = 'analysis_comparable_variant_mismatch';
    case AnalysisCostsCurrencyMismatch = 'analysis_costs_currency_mismatch';
    case AnalysisCostsEvidenceStale = 'analysis_costs_evidence_stale';
    case AnalysisCostsPriceEstimateRequired = 'analysis_costs_price_estimate_required';
    case AnalysisCostsProcessingNotFinished = 'analysis_costs_processing_not_finished';
    case BuyerDecisionEvidenceStale = 'buyer_decision_evidence_stale';
    case BuyerDecisionHistoryLimit = 'buyer_decision_history_limit';
    case BuyerDecisionTransitionNotAllowed = 'buyer_decision_transition_not_allowed';
    case ComparableIdentityRequired = 'comparable_identity_required';
    case ComparableSourceUrlInvalid = 'comparable_source_url_invalid';
    case MarketNormalizationAnalysisNotFinished = 'market_normalization_analysis_not_finished';
    case MarketNormalizationAmountLimit = 'market_normalization_amount_limit';
    case MarketNormalizationComparableMismatch = 'market_normalization_comparable_mismatch';
    case MarketNormalizationCurrencyMetadataRequired = 'market_normalization_currency_metadata_required';
    case MarketNormalizationEvidenceConfirmationRequired = 'market_normalization_evidence_confirmation_required';
    case MarketNormalizationExchangeRateMissing = 'market_normalization_exchange_rate_missing';
    case MarketNormalizationExchangeRateStale = 'market_normalization_exchange_rate_stale';
    case MarketNormalizationTargetCurrencyRequired = 'market_normalization_target_currency_required';
    case MarketNormalizationUnnecessary = 'market_normalization_unnecessary';
    case OpportunityEvidenceProcessingNotFinished = 'opportunity_evidence_processing_not_finished';
    case OpportunityEvidenceStale = 'opportunity_evidence_stale';
    case OpportunityPickupConfirmationRequired = 'opportunity_pickup_confirmation_required';
    case OpportunityProfitEstimateRequired = 'opportunity_profit_estimate_required';
}
