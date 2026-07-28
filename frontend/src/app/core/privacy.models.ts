export type PrivacyRequestType = 'data_export' | 'account_deletion';

export type PrivacyRequestStatus =
  | 'requested'
  | 'in_review'
  | 'action_required'
  | 'approved'
  | 'fulfilled'
  | 'rejected'
  | 'cancelled';

export type PrivacyRequestActorType = 'subject' | 'operator' | 'system';

export interface PrivacyRequestActor {
  readonly id: number;
  readonly name: string;
}

export interface PrivacyRequestEvent {
  readonly id: string;
  readonly sequence: number;
  readonly prior_status: PrivacyRequestStatus | null;
  readonly next_status: PrivacyRequestStatus;
  readonly actor_type: PrivacyRequestActorType;
  readonly actor: PrivacyRequestActor | null;
  readonly reason_code: string;
  readonly note: string | null;
  readonly evidence_reference: string | null;
  readonly occurred_at: string;
}

export interface PrivacyRequest {
  readonly id: string;
  readonly type: PrivacyRequestType;
  readonly status: PrivacyRequestStatus;
  readonly current_event_id: string;
  readonly event_sequence: number;
  readonly residence_country_code: string | null;
  readonly reason: string | null;
  readonly blocking_reason_codes: readonly string[];
  readonly workflow_version: string;
  readonly privacy_notice_version: string;
  readonly can_cancel: boolean;
  readonly requested_at: string;
  readonly response_target_at: string;
  readonly resolved_at: string | null;
  readonly current_event: PrivacyRequestEvent;
  readonly events: readonly PrivacyRequestEvent[];
}

export interface PrivacyRequestInput {
  readonly type: PrivacyRequestType;
  readonly residence_country_code: string | null;
  readonly reason: string | null;
  readonly privacy_notice_confirmed: true;
  readonly idempotency_key: string;
}
