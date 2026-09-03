import { CursorPage } from './listing.models';

export type SavedSearchState = 'active' | 'paused' | 'archived';
export type SavedSearchMatchStatus =
  | 'matched'
  | 'not_matched'
  | 'insufficient_evidence';
export type NotificationState = 'delivered' | 'read' | 'unread' | 'archived';
export type EmailDeliveryState =
  | 'queued'
  | 'attempting'
  | 'delivered'
  | 'failed'
  | 'exhausted'
  | 'suppressed';
export type TelegramDeliveryState = EmailDeliveryState;
export type SavedSearchNotificationChannel =
  | 'in_app'
  | 'email'
  | 'telegram';
export type NotificationFilterState = 'all' | 'unread' | 'read';
export type CatalogMatchScope = 'category' | 'brand' | 'model';

export interface CatalogReference {
  readonly id: string;
  readonly name: string;
}

export interface ProductReference {
  readonly id: string;
  readonly canonical_key: string;
  readonly name: string;
  readonly model_number: string;
  readonly brand: CatalogReference;
  readonly category: CatalogReference & { readonly slug: string };
}

export interface ProductCategoryOption extends CatalogReference {
  readonly parent_id: string | null;
  readonly slug: string;
}

export interface SavedSearchCriteria {
  readonly product_category_id: string | null;
  readonly brand_id: string | null;
  readonly product_model_id: string | null;
  readonly minimum_price_minor: number | null;
  readonly maximum_price_minor: number | null;
  readonly price_currency_code: string | null;
  readonly continent_code: string | null;
  readonly country_codes: readonly string[];
  readonly city: string | null;
  readonly radius_km: number | null;
  readonly include_cross_border: boolean;
  readonly required_keywords: readonly string[];
  readonly excluded_keywords: readonly string[];
  readonly minimum_profit_minor: number | null;
  readonly profit_currency_code: string | null;
  readonly minimum_margin_basis_points: number | null;
  readonly minimum_deal_score_basis_points: number | null;
  readonly maximum_risk_score: number | null;
}

export interface SavedSearchVersion {
  readonly id: string;
  readonly previous_version_id: string | null;
  readonly sequence: number;
  readonly title: string;
  readonly active: boolean;
  readonly criteria: SavedSearchCriteria;
  readonly catalog: {
    readonly category?: CatalogReference | null;
    readonly brand?: CatalogReference | null;
    readonly model?: (CatalogReference & { readonly model_number: string }) | null;
  };
  readonly notification_channels: readonly SavedSearchNotificationChannel[];
  readonly reason_code: string;
  readonly criteria_hash: string;
  readonly changed_by_user_id: number;
  readonly created_at: string | null;
}

export interface SavedSearch {
  readonly id: string;
  readonly owner_user_id: number;
  readonly title: string;
  readonly active: boolean;
  readonly archived: boolean;
  readonly current_version_id: string;
  readonly version_sequence: number;
  readonly current_version: SavedSearchVersion;
  readonly versions?: readonly SavedSearchVersion[];
  readonly version_count: number;
  readonly match_count: number;
  readonly matched_count?: number;
  readonly created_at: string | null;
  readonly updated_at: string | null;
  readonly archived_at: string | null;
}

export interface SavedSearchInput extends SavedSearchCriteria {
  readonly title: string;
  readonly active: boolean;
  readonly notification_channels: readonly SavedSearchNotificationChannel[];
  readonly reason_code: string;
  readonly idempotency_key: string;
  readonly expected_current_version_id?: string;
}

export interface SavedSearchMatch {
  readonly id: string;
  readonly saved_search_id: string;
  readonly saved_search_version_id: string;
  readonly listing: {
    readonly id: string;
    readonly title: string | null;
    readonly asking_price_minor: number | null;
    readonly currency_code: string | null;
    readonly source_country_code: string | null;
    readonly target_country_code: string | null;
  };
  readonly listing_snapshot_id: string;
  readonly analysis_id: string | null;
  readonly status: SavedSearchMatchStatus;
  readonly matcher_version: string;
  readonly reason_codes: readonly string[];
  readonly unknown_criteria: readonly string[];
  readonly evaluated_at: string | null;
}

export interface InAppNotification {
  readonly id: string;
  readonly alert_type: 'saved_search_match';
  readonly saved_search: {
    readonly id: string;
    readonly title: string | null;
  };
  readonly listing: {
    readonly id: string;
    readonly title: string | null;
    readonly asking_price_minor: number | null;
    readonly currency_code: string | null;
    readonly source_country_code: string | null;
  };
  readonly payload: Readonly<Record<string, unknown>>;
  readonly current_log_id: string;
  readonly state: NotificationState;
  readonly read: boolean;
  readonly triggered_at: string | null;
  readonly state_changed_at: string | null;
  readonly delivery: {
    readonly email: {
      readonly state: EmailDeliveryState;
      readonly attempt: number | null;
      readonly reason_code: string | null;
      readonly occurred_at: string | null;
    } | null;
    readonly telegram: {
      readonly state: TelegramDeliveryState;
      readonly attempt: number | null;
      readonly reason_code: string | null;
      readonly occurred_at: string | null;
    } | null;
  };
}

export type TelegramConnectionStatus =
  | 'disconnected'
  | 'pending'
  | 'connected';

export interface TelegramConnectionState {
  readonly available: boolean;
  readonly entitled: boolean;
  readonly status: TelegramConnectionStatus;
  readonly connection_id: string | null;
  readonly bot_username: string;
  readonly challenge_expires_at: string | null;
  readonly connected_at: string | null;
  readonly can_enable_delivery: boolean;
}

export interface TelegramConnectionLink {
  readonly status: Exclude<TelegramConnectionStatus, 'disconnected'>;
  readonly connection_id: string;
  readonly bot_username: string;
  readonly link_url: string | null;
  readonly challenge_expires_at: string;
  readonly connected_at: string | null;
}

export interface NotificationPage extends CursorPage<InAppNotification> {
  readonly meta: CursorPage<InAppNotification>['meta'] & {
    readonly unread_count: number;
  };
}

export interface SavedSearchFilters {
  readonly state?: SavedSearchState;
  readonly q?: string;
  readonly cursor?: string;
  readonly per_page?: number;
}

export interface SavedSearchMatchFilters {
  readonly status?: SavedSearchMatchStatus | '';
  readonly cursor?: string;
  readonly per_page?: number;
}
