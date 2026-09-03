import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { firstValueFrom } from 'rxjs';

import {
  ActualCostSnapshotInput,
  ActualPurchaseInput,
  ActualSaleInput,
  EstimateAccuracyAttributionInput,
  OwnedProductInput,
  SalePortfolioEntryInput,
  SalePortfolioEventInput,
  SellComparableInput,
  SellComparableMarketNormalizationInput,
  SellListingDraftInput,
} from './owned-product.models';
import { OwnedProductService } from './owned-product.service';

describe('OwnedProductService', () => {
  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
  });

  afterEach(() => TestBed.inject(HttpTestingController).verify());

  it('loads a tenant cursor page without accepting an organization id', async () => {
    const service = TestBed.inject(OwnedProductService);
    const http = TestBed.inject(HttpTestingController);
    const result = firstValueFrom(
      service.list({ q: 'Bosch', status: 'draft', per_page: 20 }),
    );
    const request = http.expectOne(
      (candidate) =>
        candidate.url === '/api/v1/owned-products' &&
        candidate.params.get('q') === 'Bosch' &&
        candidate.params.get('status') === 'draft' &&
        !candidate.params.has('organization_id'),
    );

    expect(request.request.method).toBe('GET');
    request.flush({
      data: [],
      links: { first: null, last: null, prev: null, next: null },
      meta: {
        path: '/api/v1/owned-products',
        per_page: 20,
        next_cursor: null,
        prev_cursor: null,
      },
    });

    expect((await result).data).toEqual([]);
  });

  it('preserves unknown null lists separately from confirmed empty lists', async () => {
    const service = TestBed.inject(OwnedProductService);
    const http = TestBed.inject(HttpTestingController);
    const input: OwnedProductInput = {
      product_category_id: null,
      brand_name: 'Bosch Professional',
      model_name: 'GSR 18V-55',
      condition: 'used_good',
      age_months: 0,
      accessories: null,
      defects: [],
      purchase_history_known: false,
      purchase_history: null,
      target_continent_code: 'EU',
      target_country_codes: ['DE', 'AT'],
      cross_border_preference: 'cross_border_allowed',
      desired_sale_speed: 'balanced',
      status: 'draft',
      notes: null,
    };
    const result = firstValueFrom(service.create(input));
    const request = http.expectOne('/api/v1/owned-products');

    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual(input);
    request.flush({ data: { id: '01JOWNED' } });

    expect((await result).id).toBe('01JOWNED');
  });

  it('uses multipart form data for private owned-product evidence', async () => {
    const service = TestBed.inject(OwnedProductService);
    const http = TestBed.inject(HttpTestingController);
    const file = new File(['image'], 'serial.jpg', { type: 'image/jpeg' });
    const result = firstValueFrom(
      service.uploadImages('01JOWNED', 'serial_label', [file]),
    );
    const request = http.expectOne('/api/v1/owned-products/01JOWNED/images');

    expect(request.request.method).toBe('POST');
    expect(request.request.body).toBeInstanceOf(FormData);
    expect((request.request.body as FormData).get('kind')).toBe('serial_label');
    request.flush({ data: [] });

    expect(await result).toEqual([]);
  });

  it('assesses an exact immutable owned-product snapshot', async () => {
    const service = TestBed.inject(OwnedProductService);
    const http = TestBed.inject(HttpTestingController);
    const result = firstValueFrom(service.assess('01JOWNED', '01JSNAPSHOT'));
    const request = http.expectOne(
      '/api/v1/owned-products/01JOWNED/assessments',
    );

    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({
      owned_product_snapshot_id: '01JSNAPSHOT',
    });
    request.flush({ data: { id: '01JASSESSMENT', status: 'ready' } });

    expect((await result).id).toBe('01JASSESSMENT');
  });

  it('loads and appends Sell price intelligence without a tenant override', async () => {
    const service = TestBed.inject(OwnedProductService);
    const http = TestBed.inject(HttpTestingController);
    const load = firstValueFrom(service.sellPriceIntelligence('01JOWNED'));
    const loadRequest = http.expectOne(
      '/api/v1/owned-products/01JOWNED/sell-intelligence',
    );

    expect(loadRequest.request.method).toBe('GET');
    loadRequest.flush({
      data: {
        assessment_current: true,
        current_assessment_id: '01JASSESSMENT',
        records: [],
        selections: [],
        price_bands: [],
        current_price_bands: [],
      },
    });
    expect((await load).current_assessment_id).toBe('01JASSESSMENT');

    const input: SellComparableInput = {
      owned_product_assessment_id: '01JASSESSMENT',
      marketplace_source_key: 'manual',
      product_variant_id: null,
      source_url: 'https://market.example/item/1',
      external_id: 'item-1',
      marketplace_name: 'Market',
      title: 'Product',
      description: null,
      listing_type: 'product',
      condition_code: 'used_good',
      seller_type: 'private',
      asking_price_minor: 20000,
      currency_code: 'EUR',
      country_code: 'DE',
      location: null,
      included_accessories: [],
      missing_accessories: [],
      published_at: null,
      observed_at: '2026-07-26T12:00:00.000Z',
    };
    const create = firstValueFrom(
      service.createSellComparable('01JOWNED', input),
    );
    const createRequest = http.expectOne(
      '/api/v1/owned-products/01JOWNED/comparables',
    );

    expect(createRequest.request.method).toBe('POST');
    expect(createRequest.request.body).toEqual(input);
    createRequest.flush({
      data: {
        record: { id: '01JRECORD' },
        selection: { id: '01JSELECTION' },
        price_band: { id: '01JBAND' },
      },
      meta: { created: true },
    });
    expect((await create).meta.created).toBe(true);

    const normalizationInput: SellComparableMarketNormalizationInput = {
      target_country_code: 'DE',
      target_currency_code: 'EUR',
      compatibility_status: 'compatible',
      market_factor_basis_points: 11000,
      shipping_minor: 1000,
      import_duty_minor: 500,
      tax_minor: 0,
      other_cost_minor: 0,
      evidence_reference: 'sell-ops-ticket-1',
      compatibility_note: 'Regional compatibility verified.',
      observed_at: '2026-07-26T13:00:00.000Z',
      evidence_confirmed: true,
    };
    const normalize = firstValueFrom(
      service.createSellComparableMarketNormalization(
        '01JOWNED',
        '01JRECORD',
        normalizationInput,
      ),
    );
    const normalizeRequest = http.expectOne(
      '/api/v1/owned-products/01JOWNED/comparables/01JRECORD/market-normalizations',
    );

    expect(normalizeRequest.request.method).toBe('POST');
    expect(normalizeRequest.request.body).toEqual(normalizationInput);
    normalizeRequest.flush({
      data: { id: '01JNORMALIZATION' },
      meta: {
        created: true,
        selection: { id: '01JSELECTION' },
        price_band: { id: '01JBAND' },
      },
    });
    expect((await normalize).meta.created).toBe(true);
  });

  it('loads and creates source-bound Sell listing drafts', async () => {
    const service = TestBed.inject(OwnedProductService);
    const http = TestBed.inject(HttpTestingController);
    const load = firstValueFrom(service.sellListingDrafts('01JOWNED'));
    const loadRequest = http.expectOne(
      '/api/v1/owned-products/01JOWNED/listing-drafts',
    );

    expect(loadRequest.request.method).toBe('GET');
    loadRequest.flush({
      data: {
        assessment_current: true,
        current_assessment_id: '01JASSESSMENT',
        available_price_bands: [],
        drafts: [],
        current_drafts: [],
      },
    });
    expect((await load).current_assessment_id).toBe('01JASSESSMENT');

    const input: SellListingDraftInput = {
      owned_product_assessment_id: '01JASSESSMENT',
      sell_price_band_id: '01JBAND',
      target_country_code: 'DE',
      target_currency_code: 'EUR',
      price_strategy: 'recommended',
      target_asking_price_minor: 22000,
      listing_language: 'de',
      price_override_reason: null,
    };
    const create = firstValueFrom(
      service.createSellListingDraft('01JOWNED', input),
    );
    const createRequest = http.expectOne(
      '/api/v1/owned-products/01JOWNED/listing-drafts',
    );

    expect(createRequest.request.method).toBe('POST');
    expect(createRequest.request.body).toEqual(input);
    createRequest.flush({
      data: { id: '01JDRAFT', listing_language: 'de' },
      meta: { created: true },
    });
    expect((await create).data.id).toBe('01JDRAFT');
  });

  it('loads and appends manual sale portfolio records', async () => {
    const service = TestBed.inject(OwnedProductService);
    const http = TestBed.inject(HttpTestingController);
    const projection = {
      assessment_current: true,
      available_listing_drafts: [],
      entries: [],
      current_entry_id: null,
    };
    const load = firstValueFrom(service.salePortfolio('01JOWNED'));
    const loadRequest = http.expectOne(
      '/api/v1/owned-products/01JOWNED/sale-portfolio',
    );

    expect(loadRequest.request.method).toBe('GET');
    loadRequest.flush({ data: projection });
    expect((await load).entries).toEqual([]);

    const entryInput: SalePortfolioEntryInput = {
      sell_listing_draft_id: '01JDRAFT',
      idempotency_key: 'b0799692-7119-46f0-8954-1b1f99ec5221',
    };
    const create = firstValueFrom(
      service.createSalePortfolioEntry('01JOWNED', entryInput),
    );
    const createRequest = http.expectOne(
      '/api/v1/owned-products/01JOWNED/sale-portfolio',
    );

    expect(createRequest.request.method).toBe('POST');
    expect(createRequest.request.body).toEqual(entryInput);
    createRequest.flush({
      data: projection,
      meta: { created: true, entry_id: '01JENTRY' },
    });
    expect((await create).meta.entry_id).toBe('01JENTRY');

    const eventInput: SalePortfolioEventInput = {
      expected_current_event_id: null,
      event_type: 'published',
      marketplace_name: 'Market',
      marketplace_key: 'market',
      external_listing_id: 'external-1',
      external_listing_url: 'https://market.example/items/external-1',
      advertised_price_minor: 22000,
      advertised_currency_code: 'EUR',
      reason_code: null,
      note: null,
      occurred_at: '2026-07-26T12:00:00.000Z',
      idempotency_key: '5d9ecc99-a71c-4c11-b5a8-d73b2b370a98',
    };
    const event = firstValueFrom(
      service.recordSalePortfolioEvent(
        '01JOWNED',
        '01JENTRY',
        eventInput,
      ),
    );
    const eventRequest = http.expectOne(
      '/api/v1/owned-products/01JOWNED/sale-portfolio/01JENTRY/events',
    );

    expect(eventRequest.request.method).toBe('POST');
    expect(eventRequest.request.body).toEqual(eventInput);
    eventRequest.flush({
      data: projection,
      meta: {
        created: true,
        event: { id: '01JEVENT', event_type: 'published' },
      },
    });
    expect((await event).meta.event.id).toBe('01JEVENT');
  });

  it('loads and records separate immutable financial outcomes', async () => {
    const service = TestBed.inject(OwnedProductService);
    const http = TestBed.inject(HttpTestingController);
    const projection = {
      purchases: [],
      current_purchase_id: null,
      cost_snapshots: [],
      current_cost_snapshot_id: null,
      sales: [],
      current_sold_sale_id: null,
      sale_portfolio_entries: [],
      realized_profits: [],
      current_realized_profit_id: null,
      estimate_attributions: [],
      current_estimate_attribution_id: null,
      estimate_accuracy_reports: [],
      current_estimate_accuracy_report_id: null,
      accuracy_unknown_facts: ['realized_profit_unavailable'],
      accuracy_available: false,
      unknown_facts: [
        'actual_purchase_missing',
        'actual_cost_snapshot_missing',
        'actual_sale_missing',
        'realized_profit_unavailable',
      ],
      complete: false,
    } as const;
    const load = firstValueFrom(service.outcomes('01JOWNED'));
    const loadRequest = http.expectOne(
      '/api/v1/owned-products/01JOWNED/outcomes',
    );

    expect(loadRequest.request.method).toBe('GET');
    loadRequest.flush({ data: projection });
    expect((await load).complete).toBe(false);

    const purchaseInput: ActualPurchaseInput = {
      expected_current_purchase_id: null,
      amount_minor: 10000,
      currency_code: 'EUR',
      reporting_currency_code: 'EUR',
      occurred_at: '2026-07-01T10:00:00.000Z',
      evidence_kind: 'receipt',
      evidence_reference: 'receipt-1',
      correction_reason: null,
      note: null,
      idempotency_key: '7faf1502-5600-4b38-b41c-ca4f87528883',
    };
    const purchase = firstValueFrom(
      service.recordActualPurchase('01JOWNED', purchaseInput),
    );
    const purchaseRequest = http.expectOne(
      '/api/v1/owned-products/01JOWNED/outcomes/purchases',
    );

    expect(purchaseRequest.request.method).toBe('POST');
    expect(purchaseRequest.request.body).toEqual(purchaseInput);
    purchaseRequest.flush({
      data: projection,
      meta: { created: true, purchase: { id: '01JPURCHASE' } },
    });
    expect((await purchase).meta.purchase.id).toBe('01JPURCHASE');

    const costInput: ActualCostSnapshotInput = {
      expected_current_cost_snapshot_id: null,
      reporting_currency_code: 'EUR',
      items: [],
      correction_reason: null,
      note: null,
      idempotency_key: '0bc93245-3b57-45df-bf7c-a862532376d5',
    };
    const costs = firstValueFrom(
      service.recordActualCostSnapshot('01JOWNED', costInput),
    );
    const costRequest = http.expectOne(
      '/api/v1/owned-products/01JOWNED/outcomes/cost-snapshots',
    );

    expect(costRequest.request.method).toBe('POST');
    expect(costRequest.request.body).toEqual(costInput);
    costRequest.flush({
      data: projection,
      meta: { created: true, cost_snapshot: { id: '01JCOSTS' } },
    });
    expect((await costs).meta.cost_snapshot.id).toBe('01JCOSTS');

    const saleInput: ActualSaleInput = {
      expected_current_sale_id: null,
      sale_portfolio_event_id: '01JEVENT',
      outcome_type: 'sold',
      amount_minor: 20000,
      currency_code: 'EUR',
      reporting_currency_code: 'EUR',
      occurred_at: '2026-07-10T10:00:00.000Z',
      evidence_kind: 'bank_statement',
      evidence_reference: 'statement-1',
      reason_code: null,
      correction_reason: null,
      note: null,
      idempotency_key: 'dc37b605-50b4-4cf2-9417-132d52cab805',
    };
    const sale = firstValueFrom(
      service.recordActualSale('01JOWNED', '01JENTRY', saleInput),
    );
    const saleRequest = http.expectOne(
      '/api/v1/owned-products/01JOWNED/sale-portfolio/01JENTRY/outcomes',
    );

    expect(saleRequest.request.method).toBe('POST');
    expect(saleRequest.request.body).toEqual(saleInput);
    saleRequest.flush({
      data: projection,
      meta: { created: true, sale: { id: '01JSALE' } },
    });
    expect((await sale).meta.sale.id).toBe('01JSALE');

    const candidates = firstValueFrom(
      service.estimateAccuracyCandidates('01JOWNED'),
    );
    const candidateRequest = http.expectOne(
      '/api/v1/owned-products/01JOWNED/outcomes/estimate-candidates',
    );

    expect(candidateRequest.request.method).toBe('GET');
    candidateRequest.flush({
      data: [
        {
          analysis_id: '01JANALYSIS',
          profit_estimate_id: '01JESTIMATE',
        },
      ],
      meta: { count: 1, limit: 50 },
    });
    expect((await candidates).data[0]?.analysis_id).toBe('01JANALYSIS');

    const accuracyInput: EstimateAccuracyAttributionInput = {
      expected_current_attribution_id: null,
      expected_current_accuracy_report_id: null,
      realized_profit_id: '01JPROFIT',
      analysis_id: '01JANALYSIS',
      profit_estimate_id: '01JESTIMATE',
      reason_code: 'original_buy_estimate_confirmed',
      evidence_kind: 'manual_confirmation',
      evidence_reference: null,
      correction_reason: null,
      note: null,
      idempotency_key: '286af6ad-139e-46b9-a2de-264f6599356c',
    };
    const accuracy = firstValueFrom(
      service.recordEstimateAccuracyAttribution(
        '01JOWNED',
        accuracyInput,
      ),
    );
    const accuracyRequest = http.expectOne(
      '/api/v1/owned-products/01JOWNED/outcomes/estimate-attributions',
    );

    expect(accuracyRequest.request.method).toBe('POST');
    expect(accuracyRequest.request.body).toEqual(accuracyInput);
    accuracyRequest.flush({
      data: projection,
      meta: {
        created: true,
        attribution: { id: '01JATTRIBUTION' },
        report: { id: '01JREPORT' },
      },
    });
    expect((await accuracy).meta.report.id).toBe('01JREPORT');
  });
});
