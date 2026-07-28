import { provideHttpClient } from '@angular/common/http';
import {
  HttpTestingController,
  provideHttpClientTesting,
} from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { firstValueFrom } from 'rxjs';

import { SavedSearchInput } from './monitoring.models';
import { MonitoringService } from './monitoring.service';

describe('MonitoringService', () => {
  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
  });

  afterEach(() => TestBed.inject(HttpTestingController).verify());

  it('loads tenant-bounded saved searches without accepting an organization id', async () => {
    const service = TestBed.inject(MonitoringService);
    const http = TestBed.inject(HttpTestingController);
    const result = firstValueFrom(
      service.savedSearches({
        state: 'active',
        q: 'Bosch',
        per_page: 20,
      }),
    );
    const request = http.expectOne(
      (candidate) =>
        candidate.url === '/api/v1/saved-searches' &&
        candidate.params.get('state') === 'active' &&
        candidate.params.get('q') === 'Bosch' &&
        !candidate.params.has('organization_id'),
    );

    expect(request.request.method).toBe('GET');
    request.flush({
      data: [],
      links: { first: null, last: null, prev: null, next: null },
      meta: {
        path: '/api/v1/saved-searches',
        per_page: 20,
        next_cursor: null,
        prev_cursor: null,
      },
    });

    expect((await result).data).toEqual([]);
  });

  it('sends explicit immutable criteria and idempotency when creating a search', async () => {
    const service = TestBed.inject(MonitoringService);
    const http = TestBed.inject(HttpTestingController);
    const input: SavedSearchInput = {
      title: 'Bosch in Germany',
      active: true,
      product_category_id: null,
      brand_id: null,
      product_model_id: null,
      minimum_price_minor: 5000,
      maximum_price_minor: 25000,
      price_currency_code: 'EUR',
      continent_code: 'EU',
      country_codes: ['DE'],
      city: null,
      radius_km: null,
      include_cross_border: false,
      required_keywords: ['bosch'],
      excluded_keywords: ['broken'],
      minimum_profit_minor: null,
      profit_currency_code: null,
      minimum_margin_basis_points: null,
      minimum_deal_score_basis_points: null,
      maximum_risk_score: null,
      notification_channels: ['in_app'],
      reason_code: 'saved_search_created',
      idempotency_key: 'c6d5d8ae-5e0a-4cda-b94d-787fc1c0dd8e',
    };
    const result = firstValueFrom(service.createSavedSearch(input));
    const request = http.expectOne('/api/v1/saved-searches');

    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual(input);
    request.flush({ data: { id: '01JSEARCH' } });

    expect((await result).id).toBe('01JSEARCH');
  });

  it('records notification state against the expected append-only ledger head', async () => {
    const service = TestBed.inject(MonitoringService);
    const http = TestBed.inject(HttpTestingController);
    const result = firstValueFrom(
      service.recordNotificationState(
        '01JALERT',
        'read',
        '01JLOG',
        '1ee4fa03-42de-4cb9-b5bc-c85ff7ae85a8',
      ),
    );
    const request = http.expectOne('/api/v1/notifications/01JALERT/state');

    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({
      event_type: 'read',
      expected_current_log_id: '01JLOG',
      idempotency_key: '1ee4fa03-42de-4cb9-b5bc-c85ff7ae85a8',
    });
    request.flush({
      data: {
        id: '01JALERT',
        state: 'read',
        current_log_id: '01JLOG2',
      },
    });

    expect((await result).state).toBe('read');
  });
});
