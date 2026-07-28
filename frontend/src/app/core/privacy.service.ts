import { HttpClient } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { map, Observable } from 'rxjs';

import { ApiEnvelope } from './api/api.models';
import { PrivacyRequest, PrivacyRequestInput } from './privacy.models';

@Injectable({ providedIn: 'root' })
export class PrivacyService {
  private readonly http = inject(HttpClient);

  requests(): Observable<readonly PrivacyRequest[]> {
    return this.http
      .get<ApiEnvelope<readonly PrivacyRequest[]>>('/api/v1/me/privacy-requests')
      .pipe(map((response) => response.data));
  }

  create(input: PrivacyRequestInput): Observable<PrivacyRequest> {
    return this.http
      .post<ApiEnvelope<PrivacyRequest>>('/api/v1/me/privacy-requests', input)
      .pipe(map((response) => response.data));
  }

  cancel(
    requestId: string,
    expectedCurrentEventId: string,
    idempotencyKey: string,
  ): Observable<PrivacyRequest> {
    return this.http
      .post<ApiEnvelope<PrivacyRequest>>(
        `/api/v1/me/privacy-requests/${encodeURIComponent(requestId)}/cancel`,
        {
          expected_current_event_id: expectedCurrentEventId,
          idempotency_key: idempotencyKey,
        },
      )
      .pipe(map((response) => response.data));
  }
}
