import { HttpClient, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { map, Observable } from 'rxjs';

import { ApiEnvelope } from './api/api.models';
import {
  BrokerRequest,
  BrokerRequestFilters,
  BrokerRequestInput,
  BrokerRequestPage,
} from './broker-request.models';

@Injectable({ providedIn: 'root' })
export class BrokerRequestService {
  private readonly http = inject(HttpClient);

  index(filters: BrokerRequestFilters = {}): Observable<BrokerRequestPage> {
    let params = new HttpParams();

    for (const [key, value] of Object.entries(filters)) {
      if (value !== undefined && value !== '') {
        params = params.set(key, String(value));
      }
    }

    return this.http.get<BrokerRequestPage>('/api/v1/broker-requests', {
      params,
    });
  }

  show(id: string): Observable<BrokerRequest> {
    return this.http
      .get<ApiEnvelope<BrokerRequest>>(
        `/api/v1/broker-requests/${encodeURIComponent(id)}`,
      )
      .pipe(map((response) => response.data));
  }

  create(input: BrokerRequestInput): Observable<BrokerRequest> {
    return this.http
      .post<ApiEnvelope<BrokerRequest>>('/api/v1/broker-requests', input)
      .pipe(map((response) => response.data));
  }

  update(id: string, input: BrokerRequestInput): Observable<BrokerRequest> {
    return this.http
      .put<ApiEnvelope<BrokerRequest>>(
        `/api/v1/broker-requests/${encodeURIComponent(id)}`,
        input,
      )
      .pipe(map((response) => response.data));
  }

  submit(
    id: string,
    expectedCurrentEventId: string,
    idempotencyKey: string,
  ): Observable<BrokerRequest> {
    return this.transition(
      id,
      'submit',
      expectedCurrentEventId,
      idempotencyKey,
    );
  }

  cancel(
    id: string,
    expectedCurrentEventId: string,
    idempotencyKey: string,
  ): Observable<BrokerRequest> {
    return this.transition(
      id,
      'cancel',
      expectedCurrentEventId,
      idempotencyKey,
    );
  }

  acceptOffer(
    requestId: string,
    offerId: string,
    expectedRequestEventId: string,
    expectedOfferEventId: string,
    idempotencyKey: string,
  ): Observable<BrokerRequest> {
    return this.http
      .post<ApiEnvelope<BrokerRequest>>(
        `/api/v1/broker-requests/${encodeURIComponent(
          requestId,
        )}/offers/${encodeURIComponent(offerId)}/accept`,
        {
          expected_request_event_id: expectedRequestEventId,
          expected_offer_event_id: expectedOfferEventId,
          idempotency_key: idempotencyKey,
        },
      )
      .pipe(map((response) => response.data));
  }

  private transition(
    id: string,
    operation: 'submit' | 'cancel',
    expectedCurrentEventId: string,
    idempotencyKey: string,
  ): Observable<BrokerRequest> {
    return this.http
      .post<ApiEnvelope<BrokerRequest>>(
        `/api/v1/broker-requests/${encodeURIComponent(id)}/${operation}`,
        {
          expected_current_event_id: expectedCurrentEventId,
          idempotency_key: idempotencyKey,
        },
      )
      .pipe(map((response) => response.data));
  }
}

export function brokerRequestIdempotencyKey(): string {
  return globalThis.crypto.randomUUID();
}
