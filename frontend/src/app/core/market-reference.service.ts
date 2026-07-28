import { HttpClient } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { map, Observable, shareReplay } from 'rxjs';

import { ApiEnvelope } from './api/api.models';
import { MarketReferenceCatalog, OrganizationMarketPreferences } from './market.models';

@Injectable({ providedIn: 'root' })
export class MarketReferenceService {
  private readonly http = inject(HttpClient);
  private readonly catalogRequest = this.http
    .get<ApiEnvelope<MarketReferenceCatalog>>('/api/v1/reference/markets')
    .pipe(
      map((response) => response.data),
      shareReplay({ bufferSize: 1, refCount: true }),
    );

  catalog(): Observable<MarketReferenceCatalog> {
    return this.catalogRequest;
  }

  preferences(): Observable<OrganizationMarketPreferences> {
    return this.http
      .get<ApiEnvelope<OrganizationMarketPreferences>>(
        '/api/v1/organization/market-preferences',
      )
      .pipe(map((response) => response.data));
  }

  save(
    preferences: OrganizationMarketPreferences,
  ): Observable<OrganizationMarketPreferences> {
    return this.http
      .put<ApiEnvelope<OrganizationMarketPreferences>>(
        '/api/v1/organization/market-preferences',
        preferences,
      )
      .pipe(map((response) => response.data));
  }
}
