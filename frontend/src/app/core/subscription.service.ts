import { HttpClient, HttpHeaders } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { map, Observable } from 'rxjs';

import { ApiEnvelope } from './api/api.models';
import {
  BillingDestination,
  BillingInterval,
  CheckoutPlanCode,
  OrganizationSubscription,
} from './subscription.models';

@Injectable({ providedIn: 'root' })
export class SubscriptionService {
  private readonly http = inject(HttpClient);

  current(): Observable<OrganizationSubscription> {
    return this.http
      .get<ApiEnvelope<OrganizationSubscription>>('/api/v1/organization/subscription')
      .pipe(map((response) => response.data));
  }

  checkout(
    plan: CheckoutPlanCode,
    interval: BillingInterval,
    idempotencyKey: string,
  ): Observable<BillingDestination> {
    return this.http
      .post<ApiEnvelope<BillingDestination>>(
        '/api/v1/organization/billing/checkout',
        { plan, interval },
        {
          headers: new HttpHeaders({ 'Idempotency-Key': idempotencyKey }),
        },
      )
      .pipe(map((response) => response.data));
  }

  portal(): Observable<BillingDestination> {
    return this.http
      .post<ApiEnvelope<BillingDestination>>(
        '/api/v1/organization/billing/portal',
        {},
      )
      .pipe(map((response) => response.data));
  }
}
