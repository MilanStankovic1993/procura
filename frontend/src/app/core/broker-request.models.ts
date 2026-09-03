import { CursorPage } from './listing.models';

export type BrokerRequestStatus =
  | 'draft'
  | 'submitted'
  | 'reviewing'
  | 'searching'
  | 'offers_available'
  | 'accepted'
  | 'completed'
  | 'cancelled';

export type BrokerProductCondition = 'any' | 'new' | 'used' | 'refurbished';

export type BrokerRequestEventType =
  | 'created'
  | 'updated'
  | 'submitted'
  | 'review_started'
  | 'search_started'
  | 'offer_presented'
  | 'offer_accepted'
  | 'transaction_completed'
  | 'cancelled';

export type BrokerRequestOfferStatus =
  | 'presented'
  | 'accepted'
  | 'not_selected';

export type BrokerRequestOfferEventType =
  | 'presented'
  | 'accepted'
  | 'not_selected';

export interface BrokerRequestOfferEvent {
  readonly id: string;
  readonly sequence: number;
  readonly event_type: BrokerRequestOfferEventType;
  readonly from_status: BrokerRequestOfferStatus | null;
  readonly to_status: BrokerRequestOfferStatus;
  readonly reason_code: string;
  readonly actor?: {
    readonly id: number;
    readonly name: string;
  } | null;
  readonly occurred_at: string;
}

export interface BrokerRequestOffer {
  readonly id: string;
  readonly status: BrokerRequestOfferStatus;
  readonly supplier_display_name: string;
  readonly item_description: string;
  readonly condition: Exclude<BrokerProductCondition, 'any'>;
  readonly quantity: number;
  readonly unit_price_minor: number;
  readonly item_subtotal_minor: number;
  readonly shipping_cost_minor: number;
  readonly tax_duty_cost_minor: number;
  readonly other_cost_minor: number;
  readonly total_minor: number;
  readonly commission_rule_version: string;
  readonly commission_rate_basis_points: number;
  readonly commission_base_minor: number;
  readonly commission_amount_minor: number;
  readonly payable_total_minor: number;
  readonly currency_code: string;
  readonly origin_country_code: string | null;
  readonly estimated_delivery_date: string | null;
  readonly valid_until: string;
  readonly is_expired: boolean;
  readonly can_accept: boolean;
  readonly warranty_months: number | null;
  readonly return_policy_summary: string | null;
  readonly current_event_id: string;
  readonly event_sequence: number;
  readonly events?: readonly BrokerRequestOfferEvent[];
  readonly presented_at: string;
  readonly accepted_at: string | null;
  readonly resolved_at: string | null;
}

export type BrokerTransactionStatus =
  | 'awaiting_payment'
  | 'payment_confirmed'
  | 'supplier_ordered'
  | 'shipped'
  | 'delivered'
  | 'completed'
  | 'cancelled';

export type BrokerTransactionEventType =
  | 'opened'
  | 'payment_confirmed'
  | 'supplier_ordered'
  | 'shipped'
  | 'delivered'
  | 'completed'
  | 'cancelled';

export type BrokerCommissionStatus =
  | 'pending'
  | 'earned'
  | 'settled'
  | 'waived';

export type BrokerCommissionEventType =
  | 'recorded'
  | 'earned'
  | 'settled'
  | 'waived';

export interface BrokerTransactionEvent {
  readonly id: string;
  readonly sequence: number;
  readonly event_type: BrokerTransactionEventType;
  readonly from_status: BrokerTransactionStatus | null;
  readonly to_status: BrokerTransactionStatus;
  readonly reason_code: string;
  readonly actor?: {
    readonly id: number;
    readonly name: string;
  } | null;
  readonly occurred_at: string;
}

export interface BrokerCommissionEvent {
  readonly id: string;
  readonly sequence: number;
  readonly event_type: BrokerCommissionEventType;
  readonly from_status: BrokerCommissionStatus | null;
  readonly to_status: BrokerCommissionStatus;
  readonly reason_code: string;
  readonly actor?: {
    readonly id: number;
    readonly name: string;
  } | null;
  readonly occurred_at: string;
}

export interface BrokerCommission {
  readonly id: string;
  readonly status: BrokerCommissionStatus;
  readonly rule_version: string;
  readonly rate_basis_points: number;
  readonly base_minor: number;
  readonly amount_minor: number;
  readonly currency_code: string;
  readonly current_event_id: string;
  readonly event_sequence: number;
  readonly events?: readonly BrokerCommissionEvent[];
  readonly recorded_at: string;
  readonly earned_at: string | null;
  readonly settled_at: string | null;
  readonly waived_at: string | null;
}

export type BrokerReportStatus = 'available' | 'purged';

export interface BrokerReport {
  readonly id: string;
  readonly status: BrokerReportStatus;
  readonly report_version: string;
  readonly locale: 'en' | 'de' | 'es' | 'fr' | 'sr-Latn';
  readonly sequence: number;
  readonly artifact_size_bytes: number;
  readonly page_count: number;
  readonly download_url: string | null;
  readonly generated_at: string;
  readonly artifact_expires_at: string;
  readonly purged_at: string | null;
}

export type BrokerPaymentCaseType = 'refund' | 'dispute';

export type BrokerPaymentCaseStatus =
  | 'open'
  | 'under_review'
  | 'resolved'
  | 'cancelled';

export type BrokerPaymentCaseOutcome =
  | 'refund_confirmed'
  | 'refund_rejected'
  | 'dispute_won'
  | 'dispute_lost';

export type BrokerPaymentCaseEventType =
  | 'opened'
  | 'review_started'
  | 'resolved'
  | 'cancelled';

export interface BrokerPaymentCaseEvent {
  readonly id: string;
  readonly sequence: number;
  readonly event_type: BrokerPaymentCaseEventType;
  readonly from_status: BrokerPaymentCaseStatus | null;
  readonly to_status: BrokerPaymentCaseStatus;
  readonly resolution_outcome: BrokerPaymentCaseOutcome | null;
  readonly reason_code: string;
  readonly actor?: {
    readonly id: number;
    readonly name: string;
  } | null;
  readonly occurred_at: string;
}

export interface BrokerPaymentCase {
  readonly id: string;
  readonly type: BrokerPaymentCaseType;
  readonly status: BrokerPaymentCaseStatus;
  readonly requested_amount_minor: number;
  readonly resolved_amount_minor: number | null;
  readonly currency_code: string;
  readonly resolution_outcome: BrokerPaymentCaseOutcome | null;
  readonly current_event_id: string;
  readonly event_sequence: number;
  readonly events?: readonly BrokerPaymentCaseEvent[];
  readonly opened_at: string;
  readonly resolved_at: string | null;
  readonly cancelled_at: string | null;
}

export interface BrokerTransaction {
  readonly id: string;
  readonly status: BrokerTransactionStatus;
  readonly broker_request_offer_id: string;
  readonly supplier_total_minor: number;
  readonly commission_amount_minor: number;
  readonly payable_total_minor: number;
  readonly currency_code: string;
  readonly current_event_id: string;
  readonly event_sequence: number;
  readonly events?: readonly BrokerTransactionEvent[];
  readonly commission: BrokerCommission;
  readonly reports?: readonly BrokerReport[];
  readonly payment_cases?: readonly BrokerPaymentCase[];
  readonly opened_at: string;
  readonly payment_confirmed_at: string | null;
  readonly ordered_at: string | null;
  readonly shipped_at: string | null;
  readonly delivered_at: string | null;
  readonly completed_at: string | null;
  readonly cancelled_at: string | null;
}

export interface BrokerRequestEvent {
  readonly id: string;
  readonly sequence: number;
  readonly event_type: BrokerRequestEventType;
  readonly from_status: BrokerRequestStatus | null;
  readonly to_status: BrokerRequestStatus;
  readonly reason_code: string;
  readonly actor?: {
    readonly id: number;
    readonly name: string;
  } | null;
  readonly occurred_at: string;
}

export interface BrokerRequest {
  readonly id: string;
  readonly status: BrokerRequestStatus;
  readonly title: string;
  readonly product_category: {
    readonly id: string;
    readonly name: string;
    readonly slug: string;
  } | null;
  readonly product_category_id: string | null;
  readonly product_description: string;
  readonly brand_preference: string | null;
  readonly model_preference: string | null;
  readonly condition_preference: BrokerProductCondition;
  readonly quantity: number;
  readonly budget_max_minor: number | null;
  readonly budget_currency_code: string | null;
  readonly target_country_codes: readonly string[];
  readonly needed_by: string | null;
  readonly notes: string | null;
  readonly requester?: {
    readonly id: number;
    readonly name: string;
  };
  readonly current_event_id: string;
  readonly event_sequence: number;
  readonly events?: readonly BrokerRequestEvent[];
  readonly offers?: readonly BrokerRequestOffer[];
  readonly transaction?: BrokerTransaction | null;
  readonly submitted_at: string | null;
  readonly resolved_at: string | null;
  readonly created_at: string;
  readonly updated_at: string;
}

export interface BrokerRequestInput {
  readonly title: string;
  readonly product_category_id: string | null;
  readonly product_description: string;
  readonly brand_preference: string | null;
  readonly model_preference: string | null;
  readonly condition_preference: BrokerProductCondition;
  readonly quantity: number;
  readonly budget_max_minor: number | null;
  readonly budget_currency_code: string | null;
  readonly target_country_codes: readonly string[];
  readonly needed_by: string | null;
  readonly notes: string | null;
  readonly idempotency_key: string;
  readonly expected_current_event_id?: string;
}

export interface BrokerRequestFilters {
  readonly q?: string;
  readonly status?: BrokerRequestStatus | '';
  readonly cursor?: string;
  readonly per_page?: number;
}

export type BrokerRequestPage = CursorPage<BrokerRequest>;
