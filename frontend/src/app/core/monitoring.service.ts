import { HttpClient, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { map, Observable } from 'rxjs';

import { ApiEnvelope } from './api/api.models';
import { CursorPage } from './listing.models';
import {
  InAppNotification,
  NotificationFilterState,
  NotificationPage,
  NotificationState,
  ProductCategoryOption,
  ProductReference,
  SavedSearch,
  SavedSearchFilters,
  SavedSearchInput,
  SavedSearchMatch,
  SavedSearchMatchFilters,
  TelegramConnectionLink,
  TelegramConnectionState,
} from './monitoring.models';

@Injectable({ providedIn: 'root' })
export class MonitoringService {
  private readonly http = inject(HttpClient);

  savedSearches(
    filters: SavedSearchFilters = {},
  ): Observable<CursorPage<SavedSearch>> {
    return this.http.get<CursorPage<SavedSearch>>('/api/v1/saved-searches', {
      params: this.params(filters),
    });
  }

  savedSearch(savedSearchId: string): Observable<SavedSearch> {
    return this.http
      .get<ApiEnvelope<SavedSearch>>(
        `/api/v1/saved-searches/${encodeURIComponent(savedSearchId)}`,
      )
      .pipe(map((response) => response.data));
  }

  createSavedSearch(input: SavedSearchInput): Observable<SavedSearch> {
    return this.http
      .post<ApiEnvelope<SavedSearch>>('/api/v1/saved-searches', input)
      .pipe(map((response) => response.data));
  }

  updateSavedSearch(
    savedSearchId: string,
    input: SavedSearchInput,
  ): Observable<SavedSearch> {
    return this.http
      .put<ApiEnvelope<SavedSearch>>(
        `/api/v1/saved-searches/${encodeURIComponent(savedSearchId)}`,
        input,
      )
      .pipe(map((response) => response.data));
  }

  archiveSavedSearch(
    savedSearchId: string,
    expectedCurrentVersionId: string,
    idempotencyKey: string,
  ): Observable<SavedSearch> {
    return this.http
      .post<ApiEnvelope<SavedSearch>>(
        `/api/v1/saved-searches/${encodeURIComponent(savedSearchId)}/archive`,
        {
          expected_current_version_id: expectedCurrentVersionId,
          reason_code: 'saved_search_archived',
          idempotency_key: idempotencyKey,
        },
      )
      .pipe(map((response) => response.data));
  }

  matches(
    savedSearchId: string,
    filters: SavedSearchMatchFilters = {},
  ): Observable<CursorPage<SavedSearchMatch>> {
    return this.http.get<CursorPage<SavedSearchMatch>>(
      `/api/v1/saved-searches/${encodeURIComponent(savedSearchId)}/matches`,
      { params: this.params(filters) },
    );
  }

  notifications(
    state: NotificationFilterState = 'all',
    cursor?: string,
  ): Observable<NotificationPage> {
    let params = new HttpParams().set('state', state).set('per_page', 20);

    if (cursor !== undefined) {
      params = params.set('cursor', cursor);
    }

    return this.http.get<NotificationPage>('/api/v1/notifications', { params });
  }

  recordNotificationState(
    notificationId: string,
    state: Extract<NotificationState, 'read' | 'unread' | 'archived'>,
    expectedCurrentLogId: string,
    idempotencyKey: string,
  ): Observable<InAppNotification> {
    return this.http
      .post<ApiEnvelope<InAppNotification>>(
        `/api/v1/notifications/${encodeURIComponent(notificationId)}/state`,
        {
          event_type: state,
          expected_current_log_id: expectedCurrentLogId,
          idempotency_key: idempotencyKey,
        },
      )
      .pipe(map((response) => response.data));
  }

  telegramConnection(): Observable<TelegramConnectionState> {
    return this.http
      .get<ApiEnvelope<TelegramConnectionState>>(
        '/api/v1/me/telegram-connection',
      )
      .pipe(map((response) => response.data));
  }

  beginTelegramConnection(): Observable<TelegramConnectionLink> {
    return this.http
      .post<ApiEnvelope<TelegramConnectionLink>>(
        '/api/v1/me/telegram-connection/link',
        {},
      )
      .pipe(map((response) => response.data));
  }

  revokeTelegramConnection(): Observable<{
    readonly status: 'disconnected';
    readonly connection_id: null;
  }> {
    return this.http
      .delete<
        ApiEnvelope<{
          readonly status: 'disconnected';
          readonly connection_id: null;
        }>
      >('/api/v1/me/telegram-connection')
      .pipe(map((response) => response.data));
  }

  searchProducts(query: string): Observable<readonly ProductReference[]> {
    return this.http
      .get<ApiEnvelope<readonly ProductReference[]>>('/api/v1/products/search', {
        params: new HttpParams().set('q', query).set('per_page', 12),
      })
      .pipe(map((response) => response.data));
  }

  productCategories(): Observable<readonly ProductCategoryOption[]> {
    return this.http
      .get<ApiEnvelope<readonly ProductCategoryOption[]>>(
        '/api/v1/product-categories',
      )
      .pipe(map((response) => response.data));
  }

  private params(filters: object): HttpParams {
    let params = new HttpParams();

    for (const [key, value] of Object.entries(filters)) {
      if (value !== undefined && value !== '') {
        params = params.set(key, String(value));
      }
    }

    return params;
  }
}

export function monitoringIdempotencyKey(): string {
  return globalThis.crypto.randomUUID();
}
