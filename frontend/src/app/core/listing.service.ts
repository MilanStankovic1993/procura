import { HttpClient, HttpHeaders, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { map, Observable } from 'rxjs';

import { ApiEnvelope } from './api/api.models';
import {
  CursorPage,
  Listing,
  ListingFilters,
  ListingImage,
  ListingImageKind,
  ListingInput,
  ListingStatus,
  MarketplaceImport,
  MarketplaceSource,
} from './listing.models';

@Injectable({ providedIn: 'root' })
export class ListingService {
  private readonly http = inject(HttpClient);

  sources(): Observable<readonly MarketplaceSource[]> {
    return this.http
      .get<ApiEnvelope<readonly MarketplaceSource[]>>('/api/v1/marketplace-sources')
      .pipe(map((response) => response.data));
  }

  imports(): Observable<CursorPage<MarketplaceImport>> {
    return this.http.get<CursorPage<MarketplaceImport>>('/api/v1/marketplace-imports', {
      params: new HttpParams().set('per_page', 20),
    });
  }

  importDetails(importId: string): Observable<MarketplaceImport> {
    return this.http
      .get<ApiEnvelope<MarketplaceImport>>(
        `/api/v1/marketplace-imports/${encodeURIComponent(importId)}`,
      )
      .pipe(map((response) => response.data));
  }

  createImport(
    file: File,
    delimiter: 'comma' | 'semicolon' | 'tab',
    defaultTargetCountryCode: string | null,
    idempotencyKey: string,
  ): Observable<MarketplaceImport> {
    const data = new FormData();
    data.append('marketplace_source_key', 'authorized_csv');
    data.append('file', file, file.name);
    data.append('delimiter', delimiter);
    data.append('authorization_confirmed', '1');

    if (defaultTargetCountryCode !== null) {
      data.append('default_target_country_code', defaultTargetCountryCode);
    }

    return this.http
      .post<ApiEnvelope<MarketplaceImport>>('/api/v1/marketplace-imports', data, {
        headers: new HttpHeaders({ 'Idempotency-Key': idempotencyKey }),
      })
      .pipe(map((response) => response.data));
  }

  list(filters: ListingFilters = {}): Observable<CursorPage<Listing>> {
    let params = new HttpParams();

    for (const [key, value] of Object.entries(filters)) {
      if (value !== undefined && value !== '') {
        params = params.set(key, String(value));
      }
    }

    return this.http.get<CursorPage<Listing>>('/api/v1/listings', { params });
  }

  get(listingId: string): Observable<Listing> {
    return this.http
      .get<ApiEnvelope<Listing>>(`/api/v1/listings/${encodeURIComponent(listingId)}`)
      .pipe(map((response) => response.data));
  }

  create(input: ListingInput): Observable<Listing> {
    return this.http
      .post<ApiEnvelope<Listing>>('/api/v1/listings', input)
      .pipe(map((response) => response.data));
  }

  updateLifecycle(
    listingId: string,
    status: ListingStatus,
    notes: string | null,
  ): Observable<Listing> {
    return this.http
      .patch<ApiEnvelope<Listing>>(`/api/v1/listings/${encodeURIComponent(listingId)}`, {
        status,
        notes,
      })
      .pipe(map((response) => response.data));
  }

  uploadImages(
    listingId: string,
    kind: ListingImageKind,
    files: readonly File[],
  ): Observable<readonly ListingImage[]> {
    const data = new FormData();
    data.append('kind', kind);
    files.forEach((file) => data.append('images[]', file, file.name));

    return this.http
      .post<ApiEnvelope<readonly ListingImage[]>>(
        `/api/v1/listings/${encodeURIComponent(listingId)}/images`,
        data,
      )
      .pipe(map((response) => response.data));
  }

  deleteImage(listingId: string, imageId: string): Observable<void> {
    return this.http.delete<void>(
      `/api/v1/listings/${encodeURIComponent(listingId)}/images/${encodeURIComponent(imageId)}`,
    );
  }
}
