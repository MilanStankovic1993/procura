import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { firstValueFrom } from 'rxjs';

import { AnalysisService } from './analysis.service';

describe('AnalysisService', () => {
  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
  });

  afterEach(() => TestBed.inject(HttpTestingController).verify());

  it('loads listing analyses within the active tenant without an organization id', async () => {
    const service = TestBed.inject(AnalysisService);
    const http = TestBed.inject(HttpTestingController);
    const result = firstValueFrom(service.list({ listing_id: '01JLISTING', per_page: 5 }));
    const request = http.expectOne(
      (candidate) =>
        candidate.url === '/api/v1/analyses' &&
        candidate.params.get('listing_id') === '01JLISTING' &&
        candidate.params.get('per_page') === '5' &&
        !candidate.params.has('organization_id'),
    );

    expect(request.request.method).toBe('GET');
    request.flush({
      data: [],
      links: { first: null, last: null, prev: null, next: null },
      meta: {
        path: '/api/v1/analyses',
        per_page: 5,
        next_cursor: null,
        prev_cursor: null,
      },
    });

    expect((await result).data).toEqual([]);
  });

  it('creates an immutable draft without submitting quota usage', async () => {
    const service = TestBed.inject(AnalysisService);
    const http = TestBed.inject(HttpTestingController);
    const result = firstValueFrom(service.createDraft('01JLISTING', 'DE'));
    const request = http.expectOne('/api/v1/buy-analyses');

    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({
      listing_id: '01JLISTING',
      target_country_code: 'DE',
    });
    request.flush({ data: { id: '01JANALYSIS', status: 'draft' } });

    expect((await result).status).toBe('draft');
  });

  it('submits a draft through a separate idempotent endpoint', async () => {
    const service = TestBed.inject(AnalysisService);
    const http = TestBed.inject(HttpTestingController);
    const result = firstValueFrom(service.submit('01JANALYSIS'));
    const request = http.expectOne('/api/v1/analyses/01JANALYSIS/submit');

    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({});
    request.flush({ data: { id: '01JANALYSIS', status: 'queued' } });

    expect((await result).status).toBe('queued');
  });

  it('adds comparable evidence without accepting an organization id', async () => {
    const service = TestBed.inject(AnalysisService);
    const http = TestBed.inject(HttpTestingController);
    const input = {
      marketplace_source_key: 'manual' as const,
      product_variant_id: null,
      source_url: 'https://market.example/listing/1',
      external_id: 'listing-1',
      marketplace_name: 'Market',
      title: 'Comparable tool',
      description: null,
      listing_type: 'product' as const,
      condition_code: 'used_good' as const,
      seller_type: 'private' as const,
      asking_price_minor: 19999,
      currency_code: 'EUR',
      country_code: 'DE',
      location: null,
      included_accessories: ['case'],
      missing_accessories: [],
      published_at: null,
      observed_at: '2026-07-25T12:00:00Z',
    };
    const result = firstValueFrom(service.createComparable('01JANALYSIS', input));
    const request = http.expectOne('/api/v1/analyses/01JANALYSIS/comparables');

    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual(input);
    expect(request.request.body.organization_id).toBeUndefined();
    request.flush({
      data: {
        id: '01JCOMPARABLE',
        product_model_id: '01JMODEL',
        product_variant_id: null,
        product_variant: null,
        title: 'Comparable tool',
        asking_price_minor: 19999,
        currency_code: 'EUR',
        country_code: 'DE',
      },
      meta: {
        created: true,
        comparable_set: {
          id: '01JSET',
          status: 'insufficient',
          items: [],
        },
      },
    });

    expect((await result).created).toBe(true);
  });

  it('records explicit market-normalization evidence and returns the recalculated set', async () => {
    const service = TestBed.inject(AnalysisService);
    const http = TestBed.inject(HttpTestingController);
    const input = {
      compatibility_status: 'compatible' as const,
      market_factor_basis_points: 11000,
      shipping_minor: 1000,
      import_duty_minor: 500,
      tax_minor: 0,
      other_cost_minor: 0,
      evidence_reference: 'https://evidence.example/us-to-de',
      compatibility_note: 'Verified equivalent market segment and landed costs.',
      observed_at: '2026-07-26T12:00:00.000Z',
      evidence_confirmed: true as const,
    };
    const result = firstValueFrom(
      service.createMarketNormalization(
        'analysis/01',
        'comparable 01',
        input,
      ),
    );
    const request = http.expectOne(
      '/api/v1/analyses/analysis%2F01/comparables/comparable%2001/market-normalizations',
    );

    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual(input);
    expect(request.request.body.organization_id).toBeUndefined();
    request.flush({
      data: {
        id: '01JNORMALIZATION',
        compatibility_status: 'compatible',
        normalized_amount_minor: 21300,
      },
      meta: {
        created: true,
        comparable_set: {
          id: '01JSET',
          status: 'ready',
          items: [],
        },
      },
    });

    expect((await result).created).toBe(true);
    expect((await result).normalization.normalized_amount_minor).toBe(21300);
    expect((await result).comparable_set.id).toBe('01JSET');
  });

  it('records a buyer decision with explicit concurrency and idempotency fields', async () => {
    const service = TestBed.inject(AnalysisService);
    const http = TestBed.inject(HttpTestingController);
    const input = {
      deal_score_id: '01JDEALSCORE00000000000001',
      expected_current_event_id: '01JDECISION000000000000001',
      next_state: 'contacted' as const,
      reason_code: 'seller_contacted',
      note: null,
      idempotency_key: '2e55c5a0-7961-4aa8-b508-a2839a13a0bf',
    };
    const result = firstValueFrom(service.recordBuyerDecision('01JANALYSIS', input));
    const request = http.expectOne('/api/v1/analyses/01JANALYSIS/buyer-decisions');

    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual(input);
    expect(request.request.body.organization_id).toBeUndefined();
    request.flush({
      data: { id: '01JANALYSIS', buyer_decision: { next_state: 'contacted' } },
      meta: {
        created: true,
        event: {
          id: '01JDECISION000000000000002',
          next_state: 'contacted',
        },
      },
    });

    expect((await result).created).toBe(true);
    expect((await result).event.next_state).toBe('contacted');
  });
});
