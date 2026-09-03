export type ListingStatus =
  | 'active'
  | 'reserved'
  | 'sold'
  | 'removed'
  | 'expired'
  | 'unknown';

export type ListingImageKind = 'product' | 'screenshot';

export interface MarketplaceSource {
  readonly key: string;
  readonly name: string;
  readonly connector_type:
    | 'manual'
    | 'url_assisted'
    | 'browser_extension'
    | 'csv'
    | 'email'
    | 'partner_feed'
    | 'official_api';
  readonly capabilities: readonly string[];
  readonly supported_country_codes: readonly string[] | null;
  readonly supported_currency_codes: readonly string[] | null;
  readonly supported_language_tags: readonly string[] | null;
  readonly geographic_coverage: string | null;
  readonly cross_border_supported: boolean;
  readonly compliance_status: 'pending_review' | 'approved' | 'suspended';
  readonly available: boolean;
  readonly asking_price_only: boolean;
  readonly transaction_price_supported: boolean;
}

export type MarketplaceImportStatus =
  | 'pending'
  | 'processing'
  | 'completed'
  | 'completed_with_errors'
  | 'failed';

export type MarketplaceImportRowStatus = 'imported' | 'rejected' | 'duplicate';

export interface MarketplaceImportRow {
  readonly id: string;
  readonly row_number: number;
  readonly status: MarketplaceImportRowStatus;
  readonly listing_id: string | null;
  readonly raw_payload: Readonly<Record<string, unknown>>;
  readonly normalized_payload: Readonly<Record<string, unknown>> | null;
  readonly validation_errors: Readonly<Record<string, readonly string[]>> | null;
  readonly row_hash: string;
  readonly processed_at: string | null;
}

export interface MarketplaceImport {
  readonly id: string;
  readonly marketplace_source: MarketplaceSource;
  readonly created_by: { readonly id: number; readonly name: string } | null;
  readonly status: MarketplaceImportStatus;
  readonly schema_version: number;
  readonly delimiter: 'comma' | 'semicolon' | 'tab';
  readonly default_target_country_code: string | null;
  readonly original_file_name: string;
  readonly mime_type: string;
  readonly size_bytes: number;
  readonly content_hash: string;
  readonly authorization_confirmed_at: string | null;
  readonly total_rows: number;
  readonly processed_rows: number;
  readonly imported_rows: number;
  readonly rejected_rows: number;
  readonly duplicate_rows: number;
  readonly processing_attempts: number;
  readonly started_at: string | null;
  readonly completed_at: string | null;
  readonly failed_at: string | null;
  readonly last_error_code: string | null;
  readonly last_error_message: string | null;
  readonly rows?: readonly MarketplaceImportRow[];
  readonly created_at: string | null;
  readonly updated_at: string | null;
}

export interface ListingImage {
  readonly id: string;
  readonly kind: ListingImageKind;
  readonly filename: string;
  readonly mime_type: string;
  readonly size_bytes: number;
  readonly width: number;
  readonly height: number;
  readonly position: number;
  readonly content_url: string;
  readonly created_at: string | null;
}

export interface ListingSnapshot {
  readonly id: string;
  readonly sequence: number;
  readonly captured_at: string | null;
  readonly source_url: string | null;
  readonly external_id: string | null;
  readonly marketplace_name: string;
  readonly title: string;
  readonly description: string | null;
  readonly asking_price_minor: number | null;
  readonly currency_code: string | null;
  readonly seller_information: string | null;
  readonly location: string | null;
  readonly source_country_code: string;
  readonly target_country_code: string;
  readonly status: ListingStatus;
  readonly notes: string | null;
  readonly content_hash: string;
}

export interface Listing {
  readonly id: string;
  readonly marketplace_source: MarketplaceSource;
  readonly source_url: string | null;
  readonly external_id: string | null;
  readonly marketplace_name: string;
  readonly title: string;
  readonly description: string | null;
  readonly asking_price_minor: number | null;
  readonly currency_code: string | null;
  readonly currency_minor_unit: number | null;
  readonly seller_information: string | null;
  readonly location: string | null;
  readonly source_country_code: string;
  readonly target_country_code: string;
  readonly status: ListingStatus;
  readonly notes: string | null;
  readonly image_count: number;
  readonly snapshot_count: number;
  readonly images?: readonly ListingImage[];
  readonly snapshots?: readonly ListingSnapshot[];
  readonly created_at: string | null;
  readonly updated_at: string | null;
}

export interface ListingInput {
  readonly marketplace_source_key: string;
  readonly source_url: string | null;
  readonly external_id: string | null;
  readonly marketplace_name: string;
  readonly title: string;
  readonly description: string | null;
  readonly asking_price_minor: number | null;
  readonly currency_code: string | null;
  readonly seller_information: string | null;
  readonly location: string | null;
  readonly source_country_code: string;
  readonly target_country_code: string;
  readonly status: ListingStatus;
  readonly notes: string | null;
}

export interface ListingFilters {
  readonly q?: string;
  readonly status?: ListingStatus | '';
  readonly source_country_code?: string;
  readonly target_country_code?: string;
  readonly cursor?: string;
  readonly per_page?: number;
}

export interface CursorLinks {
  readonly first?: string | null;
  readonly last?: string | null;
  readonly prev: string | null;
  readonly next: string | null;
}

export interface CursorMeta {
  readonly path: string;
  readonly per_page: number;
  readonly next_cursor: string | null;
  readonly prev_cursor: string | null;
}

export interface CursorPage<T> {
  readonly data: readonly T[];
  readonly links: CursorLinks;
  readonly meta: CursorMeta;
}
