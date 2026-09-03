import { CursorPage } from './listing.models';

export type AnalysisStatus =
  | 'draft'
  | 'queued'
  | 'processing'
  | 'needs_input'
  | 'completed'
  | 'failed'
  | 'archived';

export type AnalysisDispatchStatus =
  | 'pending'
  | 'dispatching'
  | 'dispatched'
  | 'processing'
  | 'completed'
  | 'failed';

export interface AnalysisListingSummary {
  readonly id: string;
  readonly title: string;
  readonly marketplace_name: string;
  readonly asking_price_minor: number | null;
  readonly currency_code: string | null;
}

export interface AnalysisSummary {
  readonly id: string;
  readonly listing: AnalysisListingSummary;
  readonly analysis_type: 'buy';
  readonly status: AnalysisStatus;
  readonly source_country_code: string;
  readonly target_country_code: string;
  readonly pipeline_version: string;
  readonly processing_attempts: number;
  readonly submitted_at: string | null;
  readonly finished_at: string | null;
  readonly failed_at: string | null;
  readonly next_retry_at: string | null;
  readonly last_error_code: string | null;
  readonly created_at: string | null;
  readonly updated_at: string | null;
}

export interface AnalysisDispatch {
  readonly id: string;
  readonly run_number: number;
  readonly pipeline_version: string;
  readonly status: AnalysisDispatchStatus;
  readonly queue_name: string;
  readonly dispatch_attempts: number;
  readonly max_processing_attempts: number;
  readonly available_at: string | null;
  readonly last_dispatch_attempt_at: string | null;
  readonly dispatched_at: string | null;
  readonly completed_at: string | null;
  readonly failed_at: string | null;
  readonly last_error: string | null;
}

export interface AiAnalysisAttempt {
  readonly id: string;
  readonly attempt_number: number;
  readonly status: 'processing' | 'completed' | 'failed';
  readonly provider: string;
  readonly model: string;
  readonly prompt_version: string;
  readonly input_hash: string;
  readonly result: Readonly<Record<string, unknown>> | null;
  readonly validation_status: 'pending' | 'valid' | 'invalid';
  readonly confidence_basis_points: number | null;
  readonly tokens_in: number | null;
  readonly tokens_out: number | null;
  readonly estimated_cost_minor: number | null;
  readonly estimated_cost_currency: string | null;
  readonly started_at: string | null;
  readonly completed_at: string | null;
  readonly error: string | null;
}

export type ProductMatchStatus = 'matched' | 'review_required' | 'unmatched';
export type ProductMatchReviewStatus =
  | 'not_required'
  | 'pending'
  | 'confirmed'
  | 'rejected';

export interface ProductMatchCandidate {
  readonly product_model_id: string;
  readonly product_variant_id: string | null;
  readonly brand: string;
  readonly model: string;
  readonly model_number: string;
  readonly variant: string | null;
  readonly category: string;
  readonly matched_alias: string;
  readonly alias_scope_country_code: string | null;
  readonly region_compatibility: 'compatible' | 'incompatible' | 'unspecified';
  readonly score: number;
}

export interface ProductMatch {
  readonly id: string;
  readonly run_number: number;
  readonly ai_analysis_id: string;
  readonly status: ProductMatchStatus;
  readonly review_status: ProductMatchReviewStatus;
  readonly method: string;
  readonly matcher_version: string;
  readonly input_hash: string;
  readonly confidence_basis_points: number;
  readonly product: Readonly<{
    id: string;
    canonical_key: string;
    brand: string;
    model: string;
    model_number: string;
    category: string;
    variant_id: string | null;
    variant: string | null;
  }> | null;
  readonly candidates: readonly ProductMatchCandidate[];
  readonly reason_codes: readonly string[];
  readonly reviewed_at: string | null;
  readonly created_at: string | null;
}

export type ComparableSetStatus = 'ready' | 'insufficient';
export type ComparableDecision = 'included' | 'excluded';
export type MarketCompatibilityStatus = 'compatible' | 'incompatible';
export type ExchangeRateDirection =
  | 'unresolved'
  | 'identity'
  | 'direct'
  | 'inverse';

export interface ComparableMarketNormalizationEvidence {
  readonly id: string;
  readonly evidence_hash: string;
  readonly calculation_version: string;
  readonly compatibility_status: MarketCompatibilityStatus;
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
  readonly rate_direction: ExchangeRateDirection | null;
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

export interface ComparableEvidence {
  readonly id: string;
  readonly source_key: string;
  readonly source_name: string;
  readonly source_identity_hash: string;
  readonly evidence_hash: string;
  readonly marketplace_name: string;
  readonly source_url: string | null;
  readonly external_id: string | null;
  readonly title: string;
  readonly listing_type: string;
  readonly condition: string;
  readonly seller_type: string;
  readonly asking_price_minor: number;
  readonly currency_code: string;
  readonly country_code: string;
  readonly included_accessories: readonly string[];
  readonly missing_accessories: readonly string[];
  readonly product_variant_id: string | null;
  readonly product_variant: string | null;
  readonly source_reliability_basis_points: number;
  readonly published_at: string | null;
  readonly observed_at: string;
  readonly market_normalization?: ComparableMarketNormalizationEvidence | null;
}

export interface ComparableSetItem {
  readonly id: string;
  readonly comparable_record_id: string;
  readonly decision: ComparableDecision;
  readonly rank: number | null;
  readonly score_basis_points: number;
  readonly factor_scores: Readonly<Record<string, number>>;
  readonly reason_codes: readonly string[];
  readonly evidence: ComparableEvidence;
}

export interface ComparableSet {
  readonly id: string;
  readonly run_number: number;
  readonly product_match_id: string;
  readonly status: ComparableSetStatus;
  readonly selector_version: string;
  readonly input_hash: string;
  readonly target_country_code: string;
  readonly target_currency_code: string | null;
  readonly candidate_count: number;
  readonly included_count: number;
  readonly excluded_count: number;
  readonly minimum_required: number;
  readonly reason_codes: readonly string[];
  readonly items: readonly ComparableSetItem[];
  readonly created_at: string | null;
}

export interface ComparableInput {
  readonly marketplace_source_key: 'manual';
  readonly product_variant_id: string | null;
  readonly source_url: string | null;
  readonly external_id: string | null;
  readonly marketplace_name: string;
  readonly title: string;
  readonly description: string | null;
  readonly listing_type:
    | 'product'
    | 'spare_part'
    | 'broken_only'
    | 'wanted'
    | 'rental'
    | 'unclear_bundle';
  readonly condition_code:
    | 'new'
    | 'like_new'
    | 'used_good'
    | 'used_fair'
    | 'used_poor'
    | 'broken'
    | 'unknown';
  readonly seller_type: 'private' | 'business' | 'unknown';
  readonly asking_price_minor: number;
  readonly currency_code: string;
  readonly country_code: string;
  readonly location: string | null;
  readonly included_accessories: readonly string[];
  readonly missing_accessories: readonly string[];
  readonly published_at: string | null;
  readonly observed_at: string;
}

export interface ComparableRecord {
  readonly id: string;
  readonly product_model_id: string;
  readonly product_variant_id: string | null;
  readonly product_variant: string | null;
  readonly title: string;
  readonly asking_price_minor: number;
  readonly currency_code: string;
  readonly country_code: string;
}

export interface ComparableCreateResponse {
  readonly record: ComparableRecord;
  readonly created: boolean;
  readonly comparable_set: ComparableSet;
}

export interface ComparableMarketNormalizationInput {
  readonly compatibility_status: MarketCompatibilityStatus;
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

export interface ComparableMarketNormalizationRecord {
  readonly id: string;
  readonly analysis_id: string;
  readonly comparable_record_id: string;
  readonly calculation_version: string;
  readonly evidence_hash: string;
  readonly compatibility_status: MarketCompatibilityStatus;
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
    readonly direction: ExchangeRateDirection | null;
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

export interface ComparableMarketNormalizationCreateResponse {
  readonly normalization: ComparableMarketNormalizationRecord;
  readonly created: boolean;
  readonly comparable_set: ComparableSet;
}

export type PriceEstimateStatus =
  | 'estimated'
  | 'low_confidence'
  | 'needs_input';
export type PriceEstimateItemDecision =
  | 'included'
  | 'outlier'
  | 'missing_rate'
  | 'stale_rate'
  | 'invalid_amount'
  | 'invalid_normalization';

export interface PriceEstimateItem {
  readonly id: string;
  readonly comparable_set_item_id: string;
  readonly comparable_record_id: string;
  readonly decision: PriceEstimateItemDecision;
  readonly position: number;
  readonly original_amount_minor: number;
  readonly original_currency_code: string;
  readonly target_amount_minor: number | null;
  readonly target_currency_code: string;
  readonly weight_basis_points: number;
  readonly exchange_rate_id: string | null;
  readonly rate_direction: ExchangeRateDirection;
  readonly rate_value: string | null;
  readonly rate_effective_at: string | null;
  readonly rate_provider: string | null;
  readonly rate_provider_reference: string | null;
  readonly reason_codes: readonly string[];
  readonly evidence: Readonly<{
    comparable_evidence: ComparableEvidence;
    selector_rank: number | null;
    selector_score_basis_points: number;
    selector_factor_scores: Readonly<Record<string, number>>;
    selector_reason_codes: readonly string[];
  }>;
}

export interface PriceEstimate {
  readonly id: string;
  readonly run_number: number;
  readonly comparable_set_id: string;
  readonly status: PriceEstimateStatus;
  readonly algorithm_version: string;
  readonly rate_resolver_version: string;
  readonly input_hash: string;
  readonly calculation_at: string;
  readonly target_country_code: string;
  readonly target_currency_code: string;
  readonly input_count: number;
  readonly included_count: number;
  readonly outlier_count: number;
  readonly unresolved_count: number;
  readonly estimate_low_minor: number | null;
  readonly estimate_minor: number | null;
  readonly estimate_high_minor: number | null;
  readonly statistics: Readonly<{
    median_minor: number | null;
    weighted_median_minor: number | null;
    q1_minor: number | null;
    q3_minor: number | null;
    mad_minor: number | null;
    dispersion_basis_points: number | null;
  }>;
  readonly confidence_basis_points: number | null;
  readonly confidence_level: 'low' | 'medium' | 'high' | null;
  readonly confidence_components: Readonly<Record<string, number>>;
  readonly reason_codes: readonly string[];
  readonly items: readonly PriceEstimateItem[];
  readonly created_at: string | null;
}

export type RiskAssessmentStatus = 'assessed';
export type RiskLevel = 'low' | 'medium' | 'high' | 'critical';
export type RiskCategory = 'listing' | 'seller' | 'product' | 'transaction';
export type RiskSeverity = 'low' | 'medium' | 'high' | 'critical';

export interface RiskSignal {
  readonly id: string;
  readonly position: number;
  readonly code: string;
  readonly category: RiskCategory;
  readonly severity: RiskSeverity;
  readonly is_unknown: boolean;
  readonly weight_points: number;
  readonly score_contribution: number;
  readonly evidence: Readonly<Record<string, unknown>>;
  readonly source: string;
  readonly confidence_basis_points: number;
  readonly verification_action: string | null;
}

export interface RiskAssessment {
  readonly id: string;
  readonly run_number: number;
  readonly product_match_id: string;
  readonly comparable_set_id: string;
  readonly price_estimate_id: string;
  readonly status: RiskAssessmentStatus;
  readonly evaluator_version: string;
  readonly input_hash: string;
  readonly calculation_at: string;
  readonly score: number;
  readonly level: RiskLevel;
  readonly confidence_basis_points: number;
  readonly confidence_level: 'low' | 'medium' | 'high';
  readonly signal_count: number;
  readonly unknown_count: number;
  readonly reason_codes: readonly string[];
  readonly confidence_components: Readonly<Record<string, number>>;
  readonly verification_actions: readonly string[];
  readonly signals: readonly RiskSignal[];
  readonly created_at: string | null;
}

export type CostCategory =
  | 'purchase_price'
  | 'transport'
  | 'repair'
  | 'platform_fees'
  | 'payment_fees'
  | 'customs'
  | 'tax'
  | 'other_costs'
  | 'safety_reserve';

export interface CostInputItem {
  readonly id: string;
  readonly position: number;
  readonly category: CostCategory;
  readonly amount_minor: number | null;
  readonly is_known: boolean;
  readonly source: 'user_confirmed' | 'user_unprovided';
}

export interface CostInput {
  readonly id: string;
  readonly run_number: number;
  readonly price_estimate_id: string;
  readonly risk_assessment_id: string;
  readonly submitted_by_user_id: number;
  readonly input_version: string;
  readonly input_hash: string;
  readonly currency_code: string;
  readonly source_country_code: string;
  readonly target_country_code: string;
  readonly regional_compatibility_confirmed: boolean | null;
  readonly known_count: number;
  readonly unknown_count: number;
  readonly items: readonly CostInputItem[];
  readonly submitted_at: string;
  readonly created_at: string | null;
}

export type ProfitEstimateStatus =
  | 'estimated'
  | 'low_confidence'
  | 'needs_input';

export interface ProfitEstimateItem {
  readonly id: string;
  readonly cost_input_item_id: string | null;
  readonly position: number;
  readonly category: 'expected_sale_price' | CostCategory;
  readonly kind: 'revenue' | 'cost';
  readonly amount_minor: number | null;
  readonly is_known: boolean;
  readonly source: Readonly<Record<string, unknown>>;
}

export interface ProfitEstimate {
  readonly id: string;
  readonly run_number: number;
  readonly price_estimate_id: string;
  readonly risk_assessment_id: string;
  readonly cost_input_id: string;
  readonly status: ProfitEstimateStatus;
  readonly calculation_version: string;
  readonly input_hash: string;
  readonly calculation_at: string;
  readonly currency_code: string;
  readonly expected_sale_price_minor: number;
  readonly purchase_price_minor: number | null;
  readonly gross_margin_minor: number | null;
  readonly known_costs_minor: number;
  readonly additional_costs_minor: number | null;
  readonly total_cost_minor: number | null;
  readonly expected_net_profit_minor: number | null;
  readonly profit_margin_basis_points: number | null;
  readonly return_on_invested_capital_basis_points: number | null;
  readonly confidence_basis_points: number;
  readonly confidence_level: 'low' | 'medium' | 'high';
  readonly unknown_count: number;
  readonly reason_codes: readonly string[];
  readonly confidence_components: Readonly<Record<string, number>>;
  readonly items: readonly ProfitEstimateItem[];
  readonly created_at: string | null;
}

export interface CostInputSubmission {
  readonly price_estimate_id: string;
  readonly risk_assessment_id: string;
  readonly currency_code: string;
  readonly purchase_price_minor: number | null;
  readonly transport_minor: number | null;
  readonly repair_minor: number | null;
  readonly platform_fees_minor: number | null;
  readonly payment_fees_minor: number | null;
  readonly customs_minor: number | null;
  readonly tax_minor: number | null;
  readonly other_costs_minor: number | null;
  readonly safety_reserve_minor: number | null;
  readonly regional_compatibility_confirmed: boolean | null;
}

export interface CostConfirmationResponse {
  readonly analysis: Analysis;
  readonly created: boolean;
}

export type OpportunityComponent = 'logistics' | 'demand';
export type OpportunityAssessmentStatus =
  | 'assessed'
  | 'low_confidence'
  | 'needs_input';
export type ShippingMethod =
  | 'local_pickup'
  | 'parcel'
  | 'seller_arranged'
  | 'freight';

export interface OpportunityInputItem {
  readonly id: string;
  readonly component: OpportunityComponent;
  readonly position: number;
  readonly code: string;
  readonly value_type: 'string' | 'integer' | 'boolean' | 'datetime';
  readonly value: string | number | boolean | null;
  readonly is_known: boolean;
  readonly is_required: boolean;
  readonly source: string;
  readonly evidence: Readonly<Record<string, unknown>>;
}

export interface OpportunityInput {
  readonly id: string;
  readonly run_number: number;
  readonly comparable_set_id: string;
  readonly price_estimate_id: string;
  readonly risk_assessment_id: string;
  readonly cost_input_id: string;
  readonly profit_estimate_id: string;
  readonly submitted_by_user_id: number;
  readonly input_version: string;
  readonly input_hash: string;
  readonly source_country_code: string;
  readonly target_country_code: string;
  readonly known_count: number;
  readonly unknown_count: number;
  readonly items: readonly OpportunityInputItem[];
  readonly submitted_at: string;
  readonly created_at: string | null;
}

export interface OpportunityAssessmentItem {
  readonly id: string;
  readonly opportunity_input_item_id: string | null;
  readonly position: number;
  readonly code: string;
  readonly maximum_points: number;
  readonly score_contribution: number | null;
  readonly is_known: boolean;
  readonly source: Readonly<Record<string, unknown>>;
}

export interface OpportunityAssessment {
  readonly id: string;
  readonly run_number: number;
  readonly opportunity_input_id: string;
  readonly profit_estimate_id: string;
  readonly component: OpportunityComponent;
  readonly status: OpportunityAssessmentStatus;
  readonly evaluator_version: string;
  readonly input_hash: string;
  readonly calculated_at: string;
  readonly score: number | null;
  readonly confidence_basis_points: number;
  readonly confidence_level: 'low' | 'medium' | 'high';
  readonly unknown_count: number;
  readonly reason_codes: readonly string[];
  readonly confidence_components: Readonly<Record<string, number>>;
  readonly items: readonly OpportunityAssessmentItem[];
  readonly created_at: string | null;
}

export interface OpportunityEvidenceSubmission {
  readonly comparable_set_id: string;
  readonly price_estimate_id: string;
  readonly risk_assessment_id: string;
  readonly cost_input_id: string;
  readonly profit_estimate_id: string;
  readonly shipping_method: ShippingMethod | null;
  readonly shipping_distance_km: number | null;
  readonly pickup_available: boolean | null;
  readonly tracking_available: boolean | null;
  readonly insurance_available: boolean | null;
  readonly packaging_confirmed: boolean | null;
  readonly cross_border_handling_confirmed: boolean | null;
  readonly sold_comparables_count: number | null;
  readonly median_days_to_sale: number | null;
  readonly observation_window_days: number | null;
  readonly demand_evidence_observed_at: string | null;
  readonly demand_evidence_source: string | null;
}

export interface OpportunityConfirmationResponse {
  readonly analysis: Analysis;
  readonly created: boolean;
}

export type DealScoreStatus = 'assessed' | 'needs_input';
export type DealRecommendation =
  | 'strong_opportunity'
  | 'potential_opportunity'
  | 'needs_verification'
  | 'weak_opportunity'
  | 'avoid'
  | 'insufficient_data';
export type DealScoreComponent =
  | 'estimated_net_margin'
  | 'price_confidence'
  | 'resale_demand'
  | 'inverse_risk'
  | 'logistics_simplicity';

export interface DealScoreCapDecision {
  readonly code: string;
  readonly maximum_score: number;
  readonly triggered: boolean;
  readonly applied: boolean;
  readonly evidence: Readonly<Record<string, unknown>>;
}

export interface DealScoreItem {
  readonly id: string;
  readonly position: number;
  readonly component: DealScoreComponent;
  readonly weight_basis_points: number;
  readonly raw_value: number | null;
  readonly raw_value_unit: string;
  readonly normalized_score_basis_points: number | null;
  readonly weighted_contribution_basis_points: number | null;
  readonly confidence_basis_points: number;
  readonly impact: 'strengthens' | 'neutral' | 'reduces' | 'unknown';
  readonly source: Readonly<Record<string, unknown>>;
}

export interface DealScore {
  readonly id: string;
  readonly run_number: number;
  readonly product_match_id: string;
  readonly price_estimate_id: string;
  readonly risk_assessment_id: string;
  readonly profit_estimate_id: string;
  readonly opportunity_input_id: string;
  readonly logistics_assessment_id: string;
  readonly demand_assessment_id: string;
  readonly status: DealScoreStatus;
  readonly calculation_version: string;
  readonly input_hash: string;
  readonly calculated_at: string;
  readonly uncapped_score: number | null;
  readonly uncapped_score_basis_points: number | null;
  readonly score: number | null;
  readonly score_basis_points: number | null;
  readonly recommendation: DealRecommendation;
  readonly confidence_basis_points: number;
  readonly confidence_level: 'low' | 'medium' | 'high';
  readonly unknown_count: number;
  readonly applicable_cap: number | null;
  readonly cap_decisions: readonly DealScoreCapDecision[];
  readonly reason_codes: readonly string[];
  readonly confidence_components: Readonly<Record<string, number>>;
  readonly factors_increasing: readonly string[];
  readonly factors_reducing: readonly string[];
  readonly assumptions: readonly string[];
  readonly verification_actions: readonly string[];
  readonly items: readonly DealScoreItem[];
  readonly created_at: string | null;
}

export type BuyerDecisionState =
  | 'interested'
  | 'contacted'
  | 'purchased'
  | 'rejected'
  | 'archived';

export interface BuyerDecisionEvent {
  readonly id: string;
  readonly sequence: number;
  readonly analysis_id: string;
  readonly deal_score_id: string;
  readonly previous_event_id: string | null;
  readonly prior_state: BuyerDecisionState | null;
  readonly next_state: BuyerDecisionState;
  readonly reason_code: string | null;
  readonly note: string | null;
  readonly actor: Readonly<{
    id: number;
    name: string;
  }>;
  readonly deal_score: Readonly<{
    id: string;
    run_number: number;
    score: number | null;
    recommendation: DealRecommendation;
  }>;
  readonly decided_at: string;
  readonly created_at: string | null;
}

export interface BuyerDecisionSubmission {
  readonly deal_score_id: string;
  readonly expected_current_event_id: string | null;
  readonly next_state: BuyerDecisionState;
  readonly reason_code: string | null;
  readonly note: string | null;
  readonly idempotency_key: string;
}

export interface BuyerDecisionResponse {
  readonly analysis: Analysis;
  readonly event: BuyerDecisionEvent;
  readonly created: boolean;
}

export interface AnalysisRequestPayload {
  readonly schema_version: string;
  readonly analysis_type: 'buy';
  readonly listing: Readonly<{
    id: string;
    snapshot_id: string;
    snapshot_sequence: number;
    snapshot_content_hash: string;
    source_url: string | null;
    external_id: string | null;
    marketplace_name: string;
    title: string;
    description: string | null;
    asking_price_minor: number | null;
    currency_code: string | null;
    seller_information: string | null;
    location: string | null;
    listing_status: string;
    captured_at: string | null;
  }>;
  readonly market_scope: Readonly<{
    source_country_code: string;
    target_country_code: string;
  }>;
  readonly evidence: readonly Readonly<{
    id: string;
    kind: string;
    checksum_sha256: string;
    mime_type: string;
    width: number;
    height: number;
  }>[];
}

export interface AnalysisResultPayload {
  readonly schema_version: string;
  readonly pipeline_version: string;
  readonly normalized_listing: Readonly<Record<string, unknown>>;
  readonly confidence_basis_points: number;
  readonly needs_input: readonly string[];
  readonly warnings: readonly string[];
  readonly completed_steps: readonly string[];
  readonly pending_steps: readonly string[];
}

export interface Analysis extends AnalysisSummary {
  readonly listing_snapshot_id: string;
  readonly request_hash: string;
  readonly request_payload: AnalysisRequestPayload;
  readonly result_payload: AnalysisResultPayload | null;
  readonly last_error_message: string | null;
  readonly current_dispatch: AnalysisDispatch | null;
  readonly attempts: readonly AiAnalysisAttempt[];
  readonly product_match: ProductMatch | null;
  readonly comparable_set: ComparableSet | null;
  readonly price_estimate: PriceEstimate | null;
  readonly risk_assessment: RiskAssessment | null;
  readonly cost_input: CostInput | null;
  readonly profit_estimate: ProfitEstimate | null;
  readonly opportunity_input: OpportunityInput | null;
  readonly logistics_assessment: OpportunityAssessment | null;
  readonly demand_assessment: OpportunityAssessment | null;
  readonly deal_score: DealScore | null;
  readonly buyer_decision: BuyerDecisionEvent | null;
  readonly buyer_decision_allowed_transitions: readonly BuyerDecisionState[];
  readonly buyer_decision_history: readonly BuyerDecisionEvent[];
  readonly buyer_decision_history_count: number;
}

export interface AnalysisFilters {
  readonly listing_id?: string;
  readonly status?: AnalysisStatus;
  readonly cursor?: string;
  readonly per_page?: number;
}

export type AnalysisPage = CursorPage<AnalysisSummary>;
