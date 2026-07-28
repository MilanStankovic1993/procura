export type OwnedProductStatus = 'draft' | 'ready' | 'archived';
export type OwnedProductCondition =
  | 'new'
  | 'like_new'
  | 'used_good'
  | 'used_fair'
  | 'used_poor'
  | 'broken'
  | 'unknown';
export type CrossBorderPreference =
  | 'unknown'
  | 'domestic_only'
  | 'cross_border_allowed'
  | 'cross_border_preferred';
export type DesiredSaleSpeed = 'unknown' | 'fast' | 'balanced' | 'maximum_value';
export type OwnedProductImageKind =
  | 'product'
  | 'serial_label'
  | 'defect'
  | 'proof_of_purchase';
export type OwnedProductAssessmentStatus =
  | 'ready'
  | 'needs_input'
  | 'review_required';
export type ProductMatchStatus = 'matched' | 'unmatched' | 'review_required';
export type ProductMatchReviewStatus =
  | 'not_required'
  | 'pending'
  | 'confirmed'
  | 'rejected';

export interface ProductCategoryReference {
  readonly id: string;
  readonly parent_id: string | null;
  readonly name: string;
  readonly slug: string;
}

export interface OwnedProductTargetCountry {
  readonly code: string;
  readonly name: string;
  readonly currency_code: string | null;
}

export interface OwnedProductImage {
  readonly id: string;
  readonly kind: OwnedProductImageKind;
  readonly filename: string;
  readonly mime_type: string;
  readonly size_bytes: number;
  readonly width: number;
  readonly height: number;
  readonly position: number;
  readonly content_url: string;
  readonly created_at: string | null;
}

export interface OwnedProductSnapshot {
  readonly id: string;
  readonly sequence: number;
  readonly captured_at: string | null;
  readonly captured_by?: {
    readonly id: number;
    readonly name: string;
  } | null;
  readonly category: ProductCategoryReference | null;
  readonly brand_name: string | null;
  readonly model_name: string | null;
  readonly condition: OwnedProductCondition;
  readonly age_months: number | null;
  readonly accessories: readonly string[] | null;
  readonly defects: readonly string[] | null;
  readonly purchase_history_known: boolean;
  readonly purchase_history: string | null;
  readonly target_continent_code: string;
  readonly target_country_codes: readonly string[];
  readonly cross_border_preference: CrossBorderPreference;
  readonly desired_sale_speed: DesiredSaleSpeed;
  readonly status: OwnedProductStatus;
  readonly notes: string | null;
  readonly content_hash: string;
}

export interface OwnedProductAssessmentCandidate {
  readonly product_model_id: string;
  readonly product_variant_id: string | null;
  readonly brand: string;
  readonly model: string;
  readonly model_number: string | null;
  readonly variant: string | null;
  readonly category: string;
  readonly matched_alias: string;
  readonly alias_scope_country_code: string | null;
  readonly region_compatibility: 'compatible' | 'incompatible' | 'unspecified';
  readonly score: number;
}

export interface OwnedProductAssessment {
  readonly id: string;
  readonly run_number: number;
  readonly owned_product_snapshot_id: string;
  readonly snapshot_sequence: number;
  readonly status: OwnedProductAssessmentStatus;
  readonly matcher_status: ProductMatchStatus;
  readonly review_status: ProductMatchReviewStatus;
  readonly method: string;
  readonly matcher_version: string;
  readonly evaluator_version: string;
  readonly input_hash: string;
  readonly confidence_basis_points: number;
  readonly completeness_basis_points: number;
  readonly product: {
    readonly id: string;
    readonly canonical_key: string;
    readonly category: string;
    readonly brand: string;
    readonly model: string;
    readonly model_number: string | null;
    readonly variant_id: string | null;
    readonly variant: string | null;
  } | null;
  readonly condition: OwnedProductCondition;
  readonly included_accessories: readonly string[] | null;
  readonly missing_accessories: readonly string[] | null;
  readonly defects: readonly string[] | null;
  readonly candidates: readonly OwnedProductAssessmentCandidate[];
  readonly reason_codes: readonly string[];
  readonly unknown_facts: readonly string[];
  readonly verification_actions: readonly string[];
  readonly assessed_by?: {
    readonly id: number;
    readonly name: string;
  } | null;
  readonly assessed_at: string | null;
  readonly created_at: string | null;
}

export interface OwnedProduct {
  readonly id: string;
  readonly category: ProductCategoryReference | null;
  readonly brand_name: string | null;
  readonly model_name: string | null;
  readonly condition: OwnedProductCondition;
  readonly age_months: number | null;
  readonly accessories: readonly string[] | null;
  readonly defects: readonly string[] | null;
  readonly purchase_history_known: boolean;
  readonly purchase_history: string | null;
  readonly target_continent_code: string;
  readonly target_countries: readonly OwnedProductTargetCountry[];
  readonly target_country_codes: readonly string[];
  readonly cross_border_preference: CrossBorderPreference;
  readonly desired_sale_speed: DesiredSaleSpeed;
  readonly status: OwnedProductStatus;
  readonly notes: string | null;
  readonly image_count: number;
  readonly snapshot_count: number;
  readonly assessment_count?: number;
  readonly images?: readonly OwnedProductImage[];
  readonly snapshots?: readonly OwnedProductSnapshot[];
  readonly assessments?: readonly OwnedProductAssessment[];
  readonly current_assessment?: OwnedProductAssessment | null;
  readonly created_at: string | null;
  readonly updated_at: string | null;
}

export interface OwnedProductInput {
  readonly product_category_id: string | null;
  readonly brand_name: string | null;
  readonly model_name: string | null;
  readonly condition: OwnedProductCondition;
  readonly age_months: number | null;
  readonly accessories: readonly string[] | null;
  readonly defects: readonly string[] | null;
  readonly purchase_history_known: boolean;
  readonly purchase_history: string | null;
  readonly target_continent_code: string;
  readonly target_country_codes: readonly string[];
  readonly cross_border_preference: CrossBorderPreference;
  readonly desired_sale_speed: DesiredSaleSpeed;
  readonly status: Exclude<OwnedProductStatus, 'archived'>;
  readonly notes: string | null;
}

export interface OwnedProductFilters {
  readonly q?: string;
  readonly status?: OwnedProductStatus | '';
  readonly target_continent_code?: string;
  readonly cursor?: string;
  readonly per_page?: number;
}

export type SellComparableListingType =
  | 'product'
  | 'spare_part'
  | 'broken_only'
  | 'wanted'
  | 'rental'
  | 'unclear_bundle';
export type SellComparableSellerType = 'private' | 'business' | 'unknown';
export type SellComparableDecision = 'included' | 'excluded';
export type SellComparableSelectionStatus = 'ready' | 'insufficient';
export type SellPriceBandStatus = 'ready' | 'low_confidence' | 'needs_input';
export type SellPriceBandItemDecision = 'included' | 'outlier';
export type SellMarketCompatibilityStatus = 'compatible' | 'incompatible';
export type SellExchangeRateDirection =
  | 'unresolved'
  | 'identity'
  | 'direct'
  | 'inverse';

export interface SellComparableMarketNormalizationEvidence {
  readonly id: string;
  readonly evidence_hash: string;
  readonly calculation_version: string;
  readonly compatibility_status: SellMarketCompatibilityStatus;
  readonly source_country_code: string;
  readonly target_country_code: string;
  readonly source_currency_code: string;
  readonly target_currency_code: string;
  readonly source_amount_minor: number;
  readonly converted_amount_minor: number | null;
  readonly market_factor_basis_points: number | null;
  readonly market_adjusted_amount_minor: number | null;
  readonly shipping_minor: number;
  readonly import_duty_minor: number;
  readonly tax_minor: number;
  readonly other_cost_minor: number;
  readonly normalized_amount_minor: number | null;
  readonly exchange_rate_id: string | null;
  readonly rate_direction: SellExchangeRateDirection | null;
  readonly rate_value: string | null;
  readonly rate_effective_at: string | null;
  readonly rate_provider: string | null;
  readonly rate_provider_reference: string | null;
  readonly evidence_reference: string;
  readonly compatibility_note: string;
  readonly reason_codes: readonly string[];
  readonly observed_at: string;
  readonly created_at: string | null;
}

export interface SellComparableMarketNormalizationInput {
  readonly target_country_code: string;
  readonly target_currency_code: string;
  readonly compatibility_status: SellMarketCompatibilityStatus;
  readonly market_factor_basis_points?: number;
  readonly shipping_minor?: number;
  readonly import_duty_minor?: number;
  readonly tax_minor?: number;
  readonly other_cost_minor?: number;
  readonly evidence_reference: string;
  readonly compatibility_note: string;
  readonly observed_at: string;
  readonly evidence_confirmed: true;
}

export interface SellComparableMarketNormalizationRecord {
  readonly id: string;
  readonly owned_product_id: string;
  readonly owned_product_assessment_id: string;
  readonly sell_comparable_record_id: string;
  readonly calculation_version: string;
  readonly evidence_hash: string;
  readonly compatibility_status: SellMarketCompatibilityStatus;
  readonly source_country_code: string;
  readonly target_country_code: string;
  readonly source_currency_code: string;
  readonly target_currency_code: string;
  readonly source_amount_minor: number;
  readonly converted_amount_minor: number | null;
  readonly market_factor_basis_points: number | null;
  readonly market_adjusted_amount_minor: number | null;
  readonly costs: {
    readonly shipping_minor: number;
    readonly import_duty_minor: number;
    readonly tax_minor: number;
    readonly other_cost_minor: number;
  };
  readonly normalized_amount_minor: number | null;
  readonly exchange_rate: {
    readonly id: string | null;
    readonly direction: SellExchangeRateDirection | null;
    readonly value: string | null;
    readonly effective_at: string | null;
    readonly provider: string | null;
    readonly provider_reference: string | null;
  };
  readonly evidence_reference: string;
  readonly compatibility_note: string;
  readonly reason_codes: readonly string[];
  readonly observed_at: string;
  readonly created_by_user_id: number | null;
  readonly created_at: string | null;
}

export interface SellComparableInput {
  readonly owned_product_assessment_id: string;
  readonly marketplace_source_key: 'manual';
  readonly product_variant_id: string | null;
  readonly source_url: string | null;
  readonly external_id: string | null;
  readonly marketplace_name: string;
  readonly title: string;
  readonly description: string | null;
  readonly listing_type: SellComparableListingType;
  readonly condition_code: OwnedProductCondition;
  readonly seller_type: SellComparableSellerType;
  readonly asking_price_minor: number;
  readonly currency_code: string;
  readonly country_code: string;
  readonly location: string | null;
  readonly included_accessories: readonly string[];
  readonly missing_accessories: readonly string[];
  readonly published_at: string | null;
  readonly observed_at: string;
}

export interface SellComparableRecord {
  readonly id: string;
  readonly owned_product_assessment_id: string;
  readonly assessment_input_hash: string;
  readonly product_model_id: string;
  readonly product_variant_id: string | null;
  readonly product_variant: string | null;
  readonly source: {
    readonly key: string;
    readonly name: string;
    readonly marketplace_name: string;
    readonly source_url: string | null;
    readonly external_id: string | null;
    readonly source_identity_hash: string;
    readonly evidence_hash: string;
  };
  readonly title: string;
  readonly description: string | null;
  readonly listing_type: SellComparableListingType;
  readonly condition: OwnedProductCondition;
  readonly seller_type: SellComparableSellerType;
  readonly asking_price_minor: number;
  readonly currency_code: string;
  readonly country_code: string;
  readonly location: string | null;
  readonly included_accessories: readonly string[];
  readonly missing_accessories: readonly string[];
  readonly source_reliability_basis_points: number;
  readonly published_at: string | null;
  readonly observed_at: string | null;
  readonly market_normalizations?: readonly SellComparableMarketNormalizationRecord[];
  readonly created_by?: {
    readonly id: number;
    readonly name: string;
  } | null;
  readonly created_at: string | null;
}

export interface SellComparableSelectionItem {
  readonly id: string;
  readonly sell_comparable_record_id: string;
  readonly decision: SellComparableDecision;
  readonly rank: number | null;
  readonly score_basis_points: number;
  readonly factor_scores: Readonly<Record<string, number>>;
  readonly reason_codes: readonly string[];
  readonly evidence: {
    readonly marketplace_name: string;
    readonly title: string;
    readonly asking_price_minor: number;
    readonly currency_code: string;
    readonly country_code: string;
    readonly observed_at: string;
    readonly market_normalization?: SellComparableMarketNormalizationEvidence | null;
  };
}

export interface SellComparableSelection {
  readonly id: string;
  readonly owned_product_assessment_id: string;
  readonly run_number: number;
  readonly status: SellComparableSelectionStatus;
  readonly selector_version: string;
  readonly input_hash: string;
  readonly target_country_code: string;
  readonly target_currency_code: string;
  readonly candidate_count: number;
  readonly included_count: number;
  readonly excluded_count: number;
  readonly minimum_required: number;
  readonly reason_codes: readonly string[];
  readonly items: readonly SellComparableSelectionItem[];
  readonly created_at: string | null;
}

export interface SellPriceBand {
  readonly id: string;
  readonly owned_product_assessment_id: string;
  readonly sell_comparable_selection_id: string;
  readonly run_number: number;
  readonly status: SellPriceBandStatus;
  readonly algorithm_version: string;
  readonly input_hash: string;
  readonly calculated_at: string | null;
  readonly target_country_code: string;
  readonly target_currency_code: string;
  readonly input_count: number;
  readonly included_count: number;
  readonly outlier_count: number;
  readonly bands: {
    readonly quick_sale: {
      readonly low_minor: number | null;
      readonly high_minor: number | null;
    };
    readonly recommended: {
      readonly low_minor: number | null;
      readonly high_minor: number | null;
    };
    readonly ambitious: {
      readonly low_minor: number | null;
      readonly high_minor: number | null;
    };
  };
  readonly statistics: {
    readonly median_minor: number | null;
    readonly weighted_median_minor: number | null;
    readonly q1_minor: number | null;
    readonly q3_minor: number | null;
    readonly mad_minor: number | null;
    readonly dispersion_basis_points: number | null;
  };
  readonly confidence_basis_points: number | null;
  readonly confidence_level: 'low' | 'medium' | 'high' | null;
  readonly completeness_basis_points: number;
  readonly confidence_components: Readonly<Record<string, number>>;
  readonly reason_codes: readonly string[];
  readonly unknown_facts: readonly string[];
  readonly verification_actions: readonly string[];
  readonly selection?: SellComparableSelection;
  readonly items: readonly {
    readonly id: string;
    readonly sell_comparable_selection_item_id: string;
    readonly sell_comparable_record_id: string;
    readonly position: number;
    readonly decision: SellPriceBandItemDecision;
    readonly asking_price_minor: number;
    readonly currency_code: string;
    readonly weight_basis_points: number;
    readonly reason_codes: readonly string[];
  }[];
  readonly created_at: string | null;
}

export interface SellPriceIntelligence {
  readonly assessment_current: boolean;
  readonly current_assessment_id: string | null;
  readonly records: readonly SellComparableRecord[];
  readonly selections: readonly SellComparableSelection[];
  readonly price_bands: readonly SellPriceBand[];
  readonly current_price_bands: readonly SellPriceBand[];
}

export interface SellComparableMutation {
  readonly record: SellComparableRecord;
  readonly selection: SellComparableSelection;
  readonly price_band: SellPriceBand;
}

export type SellListingLanguage = 'en' | 'de' | 'es' | 'fr' | 'sr-Latn';
export type SellListingDraftStatus = 'ready' | 'review_required';
export type SellPhotoReadinessStatus =
  | 'ready'
  | 'needs_photos'
  | 'review_required';
export type SellPhotoCheckStatus =
  | 'satisfied'
  | 'missing'
  | 'review_required'
  | 'not_applicable';
export type SellPriceStrategy =
  | 'quick_sale'
  | 'recommended'
  | 'ambitious';

export interface SellListingDraftInput {
  readonly owned_product_assessment_id: string;
  readonly sell_price_band_id: string;
  readonly target_country_code: string;
  readonly target_currency_code: string;
  readonly price_strategy: SellPriceStrategy;
  readonly target_asking_price_minor: number;
  readonly listing_language: SellListingLanguage;
  readonly price_override_reason: string | null;
}

export interface SellListingDraftFact {
  readonly id: string;
  readonly position: number;
  readonly fact_code: string;
  readonly source_kind: string;
  readonly source_id: string | null;
  readonly source_field: string;
  readonly is_unknown: boolean;
  readonly disclosure: string;
  readonly value: Readonly<Record<string, unknown>>;
}

export interface SellListingPhotoCheckItem {
  readonly id: string;
  readonly position: number;
  readonly check_code: string;
  readonly status: SellPhotoCheckStatus;
  readonly required: boolean;
  readonly image_kind: OwnedProductImageKind | null;
  readonly minimum_count: number;
  readonly observed_count: number;
  readonly matching_image_ids: readonly string[];
  readonly reason_codes: readonly string[];
  readonly verification_actions: readonly string[];
}

export interface SellListingDraft {
  readonly id: string;
  readonly owned_product_assessment_id: string;
  readonly sell_price_band_id: string;
  readonly run_number: number;
  readonly status: SellListingDraftStatus;
  readonly photo_readiness_status: SellPhotoReadinessStatus;
  readonly photo_readiness_basis_points: number;
  readonly listing_language: SellListingLanguage;
  readonly template_version: string;
  readonly generator_version: string;
  readonly photo_evaluator_version: string;
  readonly input_hash: string;
  readonly generated_at: string | null;
  readonly target_country_code: string;
  readonly target_currency_code: string;
  readonly price_strategy: SellPriceStrategy;
  readonly target_asking_price_minor: number;
  readonly selected_band: {
    readonly low_minor: number;
    readonly high_minor: number;
  };
  readonly price_override_reason: string | null;
  readonly title: string;
  readonly description: string;
  readonly completeness_basis_points: number;
  readonly reason_codes: readonly string[];
  readonly unknown_facts: readonly string[];
  readonly warnings: readonly string[];
  readonly verification_actions: readonly string[];
  readonly source_fact_identifiers: readonly {
    readonly kind: string;
    readonly id: string;
    readonly hash: string;
  }[];
  readonly facts: readonly SellListingDraftFact[];
  readonly photo_checklist: readonly SellListingPhotoCheckItem[];
  readonly generated_by?: {
    readonly id: number;
    readonly name: string;
  } | null;
  readonly created_at: string | null;
}

export interface SellListingDraftProjection {
  readonly assessment_current: boolean;
  readonly current_assessment_id: string | null;
  readonly available_price_bands: readonly SellPriceBand[];
  readonly drafts: readonly SellListingDraft[];
  readonly current_drafts: readonly SellListingDraft[];
}

export type SalePortfolioStatus =
  | 'draft'
  | 'listed'
  | 'reserved'
  | 'withdrawn'
  | 'expired';
export type SalePortfolioEventType =
  | 'published'
  | 'price_changed'
  | 'reserved'
  | 'withdrawn'
  | 'expired'
  | 'relisted';

export interface SalePortfolioEntryInput {
  readonly sell_listing_draft_id: string;
  readonly idempotency_key: string;
}

export interface SalePortfolioEventInput {
  readonly expected_current_event_id: string | null;
  readonly event_type: SalePortfolioEventType;
  readonly marketplace_name: string | null;
  readonly marketplace_key: string | null;
  readonly external_listing_id: string | null;
  readonly external_listing_url: string | null;
  readonly advertised_price_minor: number | null;
  readonly advertised_currency_code: string | null;
  readonly reason_code: string | null;
  readonly note: string | null;
  readonly occurred_at: string;
  readonly idempotency_key: string;
}

export interface SalePortfolioEvent {
  readonly id: string;
  readonly sale_portfolio_entry_id: string;
  readonly previous_event_id: string | null;
  readonly sequence: number;
  readonly event_type: SalePortfolioEventType;
  readonly prior_status: SalePortfolioStatus;
  readonly next_status: SalePortfolioStatus;
  readonly marketplace_name: string | null;
  readonly marketplace_key: string | null;
  readonly external_listing_id: string | null;
  readonly external_listing_url: string | null;
  readonly advertised_price_minor: number | null;
  readonly advertised_currency_code: string | null;
  readonly reason_code: string | null;
  readonly note: string | null;
  readonly actor?: {
    readonly id: number;
    readonly name: string;
  };
  readonly occurred_at: string | null;
  readonly recorded_at: string | null;
  readonly created_at: string | null;
}

export interface SalePortfolioEntry {
  readonly id: string;
  readonly owned_product_id: string;
  readonly sell_listing_draft_id: string;
  readonly sequence: number;
  readonly source_evidence_current: boolean;
  readonly current_status: SalePortfolioStatus;
  readonly current_event_id: string | null;
  readonly current_actual_sale_id?: string | null;
  readonly allowed_events: readonly SalePortfolioEventType[];
  readonly listing_draft_input_hash: string;
  readonly target_country_code: string;
  readonly target_currency_code: string;
  readonly listing_language: SellListingLanguage;
  readonly price_strategy: SellPriceStrategy;
  readonly initial_asking_price_minor: number;
  readonly listing_title: string | null;
  readonly created_by?: {
    readonly id: number;
    readonly name: string;
  };
  readonly current_event: SalePortfolioEvent | null;
  readonly events: readonly SalePortfolioEvent[];
  readonly entered_at: string | null;
  readonly created_at: string | null;
}

export interface SalePortfolioProjection {
  readonly assessment_current: boolean;
  readonly available_listing_drafts: readonly SellListingDraft[];
  readonly entries: readonly SalePortfolioEntry[];
  readonly current_entry_id: string | null;
}

export type OutcomeEvidenceKind =
  | 'receipt'
  | 'invoice'
  | 'bank_statement'
  | 'marketplace_record'
  | 'manual_confirmation'
  | 'other';
export type ActualCostCategory =
  | 'transport'
  | 'repair'
  | 'platform_fees'
  | 'payment_fees'
  | 'customs'
  | 'tax'
  | 'marketing'
  | 'other_costs';
export type ActualSaleOutcomeType = 'sold' | 'cancelled' | 'no_sale';

export interface OutcomeConversionEvidence {
  readonly exchange_rate_id: string | null;
  readonly direction: 'identity' | 'direct' | 'inverse';
  readonly rate_value: string;
  readonly effective_at: string | null;
  readonly provider: string | null;
  readonly provider_reference: string | null;
  readonly calculated_at: string | null;
}

export interface ActualPurchaseInput {
  readonly expected_current_purchase_id: string | null;
  readonly amount_minor: number;
  readonly currency_code: string;
  readonly reporting_currency_code: string;
  readonly occurred_at: string;
  readonly evidence_kind: OutcomeEvidenceKind;
  readonly evidence_reference: string | null;
  readonly correction_reason: string | null;
  readonly note: string | null;
  readonly idempotency_key: string;
}

export interface ActualPurchase {
  readonly id: string;
  readonly owned_product_id: string;
  readonly previous_purchase_id: string | null;
  readonly sequence: number;
  readonly source_amount_minor: number;
  readonly source_currency_code: string;
  readonly reporting_amount_minor: number;
  readonly reporting_currency_code: string;
  readonly conversion: OutcomeConversionEvidence;
  readonly evidence_kind: OutcomeEvidenceKind;
  readonly evidence_reference: string | null;
  readonly correction_reason: string | null;
  readonly note: string | null;
  readonly input_hash: string;
  readonly actor?: {
    readonly id: number;
    readonly name: string;
  };
  readonly occurred_at: string | null;
  readonly recorded_at: string | null;
  readonly created_at: string | null;
}

export interface ActualCostItemInput {
  readonly category: ActualCostCategory;
  readonly is_known: boolean;
  readonly amount_minor: number | null;
  readonly currency_code: string | null;
  readonly occurred_at: string | null;
  readonly evidence_kind: OutcomeEvidenceKind | null;
  readonly evidence_reference: string | null;
  readonly note: string | null;
}

export interface ActualCostSnapshotInput {
  readonly expected_current_cost_snapshot_id: string | null;
  readonly reporting_currency_code: string;
  readonly items: readonly ActualCostItemInput[];
  readonly correction_reason: string | null;
  readonly note: string | null;
  readonly idempotency_key: string;
}

export interface ActualCostItem {
  readonly id: string;
  readonly position: number;
  readonly category: ActualCostCategory;
  readonly is_known: boolean;
  readonly source_amount_minor: number | null;
  readonly source_currency_code: string | null;
  readonly reporting_amount_minor: number | null;
  readonly reporting_currency_code: string;
  readonly conversion: OutcomeConversionEvidence | null;
  readonly occurred_at: string | null;
  readonly evidence_kind: OutcomeEvidenceKind | null;
  readonly evidence_reference: string | null;
  readonly note: string | null;
  readonly evidence_hash: string;
}

export interface ActualCostSnapshot {
  readonly id: string;
  readonly owned_product_id: string;
  readonly previous_snapshot_id: string | null;
  readonly sequence: number;
  readonly reporting_currency_code: string;
  readonly known_count: number;
  readonly unknown_count: number;
  readonly known_reporting_total_minor: number;
  readonly correction_reason: string | null;
  readonly note: string | null;
  readonly input_hash: string;
  readonly actor?: {
    readonly id: number;
    readonly name: string;
  };
  readonly items: readonly ActualCostItem[];
  readonly recorded_at: string | null;
  readonly created_at: string | null;
}

export interface ActualSaleInput {
  readonly expected_current_sale_id: string | null;
  readonly sale_portfolio_event_id: string;
  readonly outcome_type: ActualSaleOutcomeType;
  readonly amount_minor: number | null;
  readonly currency_code: string | null;
  readonly reporting_currency_code: string | null;
  readonly occurred_at: string;
  readonly evidence_kind: OutcomeEvidenceKind;
  readonly evidence_reference: string | null;
  readonly reason_code: string | null;
  readonly correction_reason: string | null;
  readonly note: string | null;
  readonly idempotency_key: string;
}

export interface ActualSale {
  readonly id: string;
  readonly owned_product_id: string;
  readonly sale_portfolio_entry_id: string;
  readonly sale_portfolio_event_id: string;
  readonly previous_sale_id: string | null;
  readonly sequence: number;
  readonly outcome_type: ActualSaleOutcomeType;
  readonly source_amount_minor: number | null;
  readonly source_currency_code: string | null;
  readonly reporting_amount_minor: number | null;
  readonly reporting_currency_code: string | null;
  readonly conversion: OutcomeConversionEvidence | null;
  readonly listed_at: string | null;
  readonly sale_duration_seconds: number;
  readonly evidence_kind: OutcomeEvidenceKind;
  readonly evidence_reference: string | null;
  readonly reason_code: string | null;
  readonly correction_reason: string | null;
  readonly note: string | null;
  readonly input_hash: string;
  readonly actor?: {
    readonly id: number;
    readonly name: string;
  };
  readonly occurred_at: string | null;
  readonly recorded_at: string | null;
  readonly created_at: string | null;
}

export interface RealizedProfit {
  readonly id: string;
  readonly owned_product_id: string;
  readonly actual_purchase_id: string;
  readonly actual_cost_snapshot_id: string;
  readonly actual_sale_id: string;
  readonly run_number: number;
  readonly calculation_version: string;
  readonly input_hash: string;
  readonly currency_code: string;
  readonly purchase_price_minor: number;
  readonly sale_price_minor: number;
  readonly actual_costs_minor: number;
  readonly total_invested_minor: number;
  readonly net_profit_minor: number;
  readonly profit_margin_basis_points: number | null;
  readonly return_on_invested_capital_basis_points: number | null;
  readonly sale_duration_seconds: number;
  readonly reason_codes: readonly string[];
  readonly calculated_at: string | null;
  readonly created_at: string | null;
}

export type EstimateAccuracyStatus =
  | 'calculated'
  | 'partial'
  | 'unavailable';

export interface EstimateAccuracyCandidate {
  readonly analysis_id: string;
  readonly profit_estimate_id: string;
  readonly listing: {
    readonly id: string;
    readonly title: string;
    readonly marketplace_name: string;
  };
  readonly currency_code: string;
  readonly expected_purchase_price_minor: number;
  readonly expected_additional_costs_minor: number;
  readonly expected_sale_price_minor: number;
  readonly expected_net_profit_minor: number;
  readonly calculation_at: string | null;
}

export interface EstimateAccuracyAttributionInput {
  readonly expected_current_attribution_id: string | null;
  readonly expected_current_accuracy_report_id: string | null;
  readonly realized_profit_id: string;
  readonly analysis_id: string;
  readonly profit_estimate_id: string;
  readonly reason_code: string;
  readonly evidence_kind: OutcomeEvidenceKind;
  readonly evidence_reference: string | null;
  readonly correction_reason: string | null;
  readonly note: string | null;
  readonly idempotency_key: string;
}

export interface OutcomeEstimateAttribution {
  readonly id: string;
  readonly owned_product_id: string;
  readonly realized_profit_id: string;
  readonly analysis_id: string;
  readonly profit_estimate_id: string;
  readonly previous_attribution_id: string | null;
  readonly sequence: number;
  readonly reason_code: string;
  readonly evidence_kind: OutcomeEvidenceKind;
  readonly evidence_reference: string | null;
  readonly correction_reason: string | null;
  readonly note: string | null;
  readonly input_hash: string;
  readonly actor?: {
    readonly id: number;
    readonly name: string;
  };
  readonly estimate_source?: {
    readonly analysis_id: string;
    readonly listing_id: string;
    readonly listing_title: string | null;
  };
  readonly attributed_at: string | null;
  readonly created_at: string | null;
}

export interface EstimateAccuracyMetric {
  readonly source_expected_minor: number;
  readonly expected_minor: number | null;
  readonly actual_minor: number;
  readonly signed_error_minor: number | null;
  readonly absolute_error_minor: number | null;
  readonly signed_error_basis_points: number | null;
  readonly absolute_percentage_error_basis_points: number | null;
}

export interface EstimateAccuracyReport {
  readonly id: string;
  readonly owned_product_id: string;
  readonly outcome_estimate_attribution_id: string;
  readonly realized_profit_id: string;
  readonly analysis_id: string;
  readonly profit_estimate_id: string;
  readonly previous_report_id: string | null;
  readonly sequence: number;
  readonly status: EstimateAccuracyStatus;
  readonly calculation_version: string;
  readonly input_hash: string;
  readonly source_currency_code: string;
  readonly reporting_currency_code: string;
  readonly conversion: {
    readonly exchange_rate_id: string | null;
    readonly direction: 'unresolved' | 'identity' | 'direct' | 'inverse';
    readonly rate_value: string | null;
    readonly effective_at: string | null;
    readonly provider: string | null;
    readonly provider_reference: string | null;
    readonly calculated_at: string | null;
  };
  readonly metrics: Readonly<
    Record<
      'purchase_price' | 'additional_costs' | 'sale_price' | 'net_profit',
      EstimateAccuracyMetric
    >
  >;
  readonly duration: {
    readonly expected_seconds: number | null;
    readonly actual_seconds: number;
    readonly signed_error_seconds: number | null;
    readonly absolute_error_seconds: number | null;
  };
  readonly reason_codes: readonly string[];
  readonly unavailable_metrics: readonly string[];
  readonly calculated_at: string | null;
  readonly created_at: string | null;
}

export interface OutcomeTrackingProjection {
  readonly purchases: readonly ActualPurchase[];
  readonly current_purchase_id: string | null;
  readonly cost_snapshots: readonly ActualCostSnapshot[];
  readonly current_cost_snapshot_id: string | null;
  readonly sales: readonly ActualSale[];
  readonly current_sold_sale_id: string | null;
  readonly sale_portfolio_entries: readonly SalePortfolioEntry[];
  readonly realized_profits: readonly RealizedProfit[];
  readonly current_realized_profit_id: string | null;
  readonly estimate_attributions: readonly OutcomeEstimateAttribution[];
  readonly current_estimate_attribution_id: string | null;
  readonly estimate_accuracy_reports: readonly EstimateAccuracyReport[];
  readonly current_estimate_accuracy_report_id: string | null;
  readonly accuracy_unknown_facts: readonly string[];
  readonly accuracy_available: boolean;
  readonly unknown_facts: readonly string[];
  readonly complete: boolean;
}
