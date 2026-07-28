import { TestBed } from '@angular/core/testing';
import { of } from 'rxjs';

import { MarketReferenceService } from '../../../core/market-reference.service';
import {
  OutcomeTrackingProjection,
  OwnedProduct,
  SalePortfolioEntry,
} from '../../../core/owned-product.models';
import { OwnedProductService } from '../../../core/owned-product.service';
import { OwnedProductOutcomePanelComponent } from './owned-product-outcome-panel.component';

const outcomeProduct = {
  id: '01JOWNED',
  category: null,
  brand_name: 'Bosch',
  model_name: 'GSR',
  condition: 'used_good',
  age_months: 18,
  accessories: [],
  defects: [],
  purchase_history_known: true,
  purchase_history: 'Private receipt',
  target_continent_code: 'EU',
  target_countries: [{ code: 'DE', name: 'Germany', currency_code: 'EUR' }],
  target_country_codes: ['DE'],
  cross_border_preference: 'domestic_only',
  desired_sale_speed: 'balanced',
  status: 'ready',
  notes: null,
  image_count: 4,
  snapshot_count: 1,
  assessment_count: 1,
  current_assessment: null,
  assessments: [],
  created_at: '2026-07-26T11:00:00Z',
  updated_at: '2026-07-26T11:00:00Z',
} as OwnedProduct;

const listedEntry = {
  id: '01JENTRY',
  owned_product_id: '01JOWNED',
  sell_listing_draft_id: '01JDRAFT',
  sequence: 1,
  source_evidence_current: true,
  current_status: 'listed',
  current_event_id: '01JEVENT',
  current_actual_sale_id: null,
  allowed_events: ['price_changed', 'reserved', 'withdrawn'],
  listing_draft_input_hash: 'c'.repeat(64),
  target_country_code: 'DE',
  target_currency_code: 'EUR',
  listing_language: 'en',
  price_strategy: 'recommended',
  initial_asking_price_minor: 22000,
  listing_title: 'Bosch GSR — used, good condition',
  current_event: {
    id: '01JEVENT',
    sale_portfolio_entry_id: '01JENTRY',
    previous_event_id: null,
    sequence: 1,
    event_type: 'published',
    prior_status: 'draft',
    next_status: 'listed',
    marketplace_name: 'Example Market',
    marketplace_key: 'example-market',
    external_listing_id: 'EXT-1',
    external_listing_url: 'https://market.example/items/EXT-1',
    advertised_price_minor: 22000,
    advertised_currency_code: 'EUR',
    reason_code: null,
    note: null,
    occurred_at: '2026-07-20T10:00:00Z',
    recorded_at: '2026-07-20T10:01:00Z',
    created_at: '2026-07-20T10:01:00Z',
  },
  events: [],
  entered_at: '2026-07-20T09:00:00Z',
  created_at: '2026-07-20T09:00:00Z',
} as SalePortfolioEntry;

const outcomeProjection: OutcomeTrackingProjection = {
  purchases: [],
  current_purchase_id: null,
  cost_snapshots: [],
  current_cost_snapshot_id: null,
  sales: [],
  current_sold_sale_id: null,
  sale_portfolio_entries: [listedEntry],
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
};

const accuracyProjection: OutcomeTrackingProjection = {
  ...outcomeProjection,
  realized_profits: [
    {
      id: '01JPROFIT',
      owned_product_id: '01JOWNED',
      actual_purchase_id: '01JPURCHASE',
      actual_cost_snapshot_id: '01JCOSTS',
      actual_sale_id: '01JSALE',
      run_number: 1,
      calculation_version: 'realized-profit:v1',
      input_hash: 'a'.repeat(64),
      currency_code: 'EUR',
      purchase_price_minor: 10000,
      sale_price_minor: 20000,
      actual_costs_minor: 3000,
      total_invested_minor: 13000,
      net_profit_minor: 7000,
      profit_margin_basis_points: 3500,
      return_on_invested_capital_basis_points: 5385,
      sale_duration_seconds: 259200,
      reason_codes: ['complete_realized_evidence_chain'],
      calculated_at: '2026-07-26T10:00:00Z',
      created_at: '2026-07-26T10:00:00Z',
    },
  ],
  current_realized_profit_id: '01JPROFIT',
  estimate_attributions: [
    {
      id: '01JATTRIBUTION',
      owned_product_id: '01JOWNED',
      realized_profit_id: '01JPROFIT',
      analysis_id: '01JANALYSIS',
      profit_estimate_id: '01JESTIMATE',
      previous_attribution_id: null,
      sequence: 1,
      reason_code: 'original_buy_estimate_confirmed',
      evidence_kind: 'manual_confirmation',
      evidence_reference: null,
      correction_reason: null,
      note: null,
      input_hash: 'b'.repeat(64),
      estimate_source: {
        analysis_id: '01JANALYSIS',
        listing_id: '01JLISTING',
        listing_title: 'Bosch GSR original buy estimate',
      },
      attributed_at: '2026-07-26T10:01:00Z',
      created_at: '2026-07-26T10:01:00Z',
    },
  ],
  current_estimate_attribution_id: '01JATTRIBUTION',
  estimate_accuracy_reports: [
    {
      id: '01JREPORT',
      owned_product_id: '01JOWNED',
      outcome_estimate_attribution_id: '01JATTRIBUTION',
      realized_profit_id: '01JPROFIT',
      analysis_id: '01JANALYSIS',
      profit_estimate_id: '01JESTIMATE',
      previous_report_id: null,
      sequence: 1,
      status: 'calculated',
      calculation_version: 'estimate-accuracy:v1',
      input_hash: 'c'.repeat(64),
      source_currency_code: 'EUR',
      reporting_currency_code: 'EUR',
      conversion: {
        exchange_rate_id: null,
        direction: 'identity',
        rate_value: '1.000000000000000000',
        effective_at: null,
        provider: null,
        provider_reference: null,
        calculated_at: '2026-07-26T10:01:00Z',
      },
      metrics: {
        purchase_price: {
          source_expected_minor: 9000,
          expected_minor: 9000,
          actual_minor: 10000,
          signed_error_minor: 1000,
          absolute_error_minor: 1000,
          signed_error_basis_points: 1111,
          absolute_percentage_error_basis_points: 1111,
        },
        additional_costs: {
          source_expected_minor: 3000,
          expected_minor: 3000,
          actual_minor: 3000,
          signed_error_minor: 0,
          absolute_error_minor: 0,
          signed_error_basis_points: 0,
          absolute_percentage_error_basis_points: 0,
        },
        sale_price: {
          source_expected_minor: 19000,
          expected_minor: 19000,
          actual_minor: 20000,
          signed_error_minor: 1000,
          absolute_error_minor: 1000,
          signed_error_basis_points: 526,
          absolute_percentage_error_basis_points: 526,
        },
        net_profit: {
          source_expected_minor: 7000,
          expected_minor: 7000,
          actual_minor: 7000,
          signed_error_minor: 0,
          absolute_error_minor: 0,
          signed_error_basis_points: 0,
          absolute_percentage_error_basis_points: 0,
        },
      },
      duration: {
        expected_seconds: null,
        actual_seconds: 259200,
        signed_error_seconds: null,
        absolute_error_seconds: null,
      },
      reason_codes: [
        'identity_currency_conversion',
        'expected_sale_duration_unavailable',
        'monetary_accuracy_calculated',
      ],
      unavailable_metrics: ['sale_duration'],
      calculated_at: '2026-07-26T10:01:00Z',
      created_at: '2026-07-26T10:01:00Z',
    },
  ],
  current_estimate_accuracy_report_id: '01JREPORT',
  accuracy_unknown_facts: [],
  accuracy_available: true,
  unknown_facts: [],
  complete: true,
};

describe('OwnedProductOutcomePanelComponent', () => {
  it('renders explicit unknowns and never preselects realized money', () => {
    TestBed.configureTestingModule({
      imports: [OwnedProductOutcomePanelComponent],
      providers: [
        {
          provide: OwnedProductService,
          useValue: {
            outcomes: vi.fn().mockReturnValue(of(outcomeProjection)),
            recordActualPurchase: vi.fn(),
            recordActualCostSnapshot: vi.fn(),
            recordActualSale: vi.fn(),
          },
        },
        {
          provide: MarketReferenceService,
          useValue: {
            catalog: vi.fn().mockReturnValue(
              of({
                version: 'test',
                continents: [],
                currencies: [
                  {
                    code: 'EUR',
                    numeric_code: '978',
                    name: 'Euro',
                    symbol: '€',
                    minor_unit: 2,
                    cash_minor_unit: 2,
                  },
                ],
              }),
            ),
          },
        },
      ],
    });
    const fixture = TestBed.createComponent(OwnedProductOutcomePanelComponent);
    fixture.componentRef.setInput('record', outcomeProduct);
    fixture.componentRef.setInput('canManage', true);
    fixture.detectChanges();
    const text = fixture.nativeElement.textContent as string;
    const purchaseAmount = fixture.nativeElement.querySelector(
      '#outcome-purchase-amount',
    ) as HTMLInputElement;
    const purchaseCurrency = fixture.nativeElement.querySelector(
      '#outcome-purchase-currency',
    ) as HTMLSelectElement;
    const saleEntry = fixture.nativeElement.querySelector(
      '#outcome-sale-entry',
    ) as HTMLSelectElement;
    const saleType = fixture.nativeElement.querySelector('#outcome-sale-type') as HTMLSelectElement;

    expect(text).toContain('Actual purchase, costs and sale');
    expect(text).toContain('Realized profit is not available yet');
    expect(text).toContain('Actual purchase evidence is missing.');
    expect(purchaseAmount.value).toBe('');
    expect(purchaseCurrency.value).toBe('');
    expect(saleEntry.value).toBe('');
    expect(saleType.value).toBe('');
    expect(fixture.nativeElement.querySelector('#outcome-sale-amount')).toBeNull();

    saleEntry.value = '01JENTRY';
    saleEntry.dispatchEvent(new Event('change'));
    fixture.detectChanges();
    const refreshedSaleType = fixture.nativeElement.querySelector(
      '#outcome-sale-type',
    ) as HTMLSelectElement;
    refreshedSaleType.value = 'sold';
    refreshedSaleType.dispatchEvent(new Event('change'));
    fixture.detectChanges();

    expect(fixture.nativeElement.querySelector('#outcome-sale-amount')).not.toBeNull();
    expect(
      (fixture.nativeElement.querySelector('#outcome-cost-transport') as HTMLInputElement).value,
    ).toBe('');
  });

  it('renders metric-level accuracy and the explicit correction boundary', () => {
    TestBed.configureTestingModule({
      imports: [OwnedProductOutcomePanelComponent],
      providers: [
        {
          provide: OwnedProductService,
          useValue: {
            outcomes: vi.fn().mockReturnValue(of(accuracyProjection)),
            estimateAccuracyCandidates: vi.fn().mockReturnValue(
              of({
                data: [
                  {
                    analysis_id: '01JANALYSIS',
                    profit_estimate_id: '01JESTIMATE',
                    listing: {
                      id: '01JLISTING',
                      title: 'Bosch GSR original buy estimate',
                      marketplace_name: 'Manual',
                    },
                    currency_code: 'EUR',
                    expected_purchase_price_minor: 9000,
                    expected_additional_costs_minor: 3000,
                    expected_sale_price_minor: 19000,
                    expected_net_profit_minor: 7000,
                    calculation_at: '2026-07-01T10:00:00Z',
                  },
                ],
                meta: { count: 1, limit: 50 },
              }),
            ),
            recordActualPurchase: vi.fn(),
            recordActualCostSnapshot: vi.fn(),
            recordActualSale: vi.fn(),
            recordEstimateAccuracyAttribution: vi.fn(),
          },
        },
        {
          provide: MarketReferenceService,
          useValue: {
            catalog: vi.fn().mockReturnValue(
              of({
                version: 'test',
                continents: [],
                currencies: [
                  {
                    code: 'EUR',
                    numeric_code: '978',
                    name: 'Euro',
                    symbol: '€',
                    minor_unit: 2,
                    cash_minor_unit: 2,
                  },
                ],
              }),
            ),
          },
        },
      ],
    });
    const fixture = TestBed.createComponent(OwnedProductOutcomePanelComponent);
    fixture.componentRef.setInput('record', outcomeProduct);
    fixture.componentRef.setInput('canManage', true);
    fixture.detectChanges();
    const text = fixture.nativeElement.textContent as string;

    expect(text).toContain('Estimate accuracy');
    expect(text).toContain('Calculated');
    expect(text).toContain('Bosch GSR original buy estimate');
    expect(text).toContain('Purchase price');
    expect(text).toContain('Signed error');
    expect(fixture.nativeElement.querySelector('#outcome-accuracy-correction')).not.toBeNull();

    const accuracySearch = fixture.nativeElement.querySelector(
      '#outcome-accuracy-search',
    ) as HTMLInputElement;
    accuracySearch.value = 'Bosch GSR';
    accuracySearch.dispatchEvent(new Event('input'));
    (
      fixture.nativeElement.querySelector('#outcome-accuracy-search-button') as HTMLButtonElement
    ).click();
    fixture.detectChanges();

    expect(
      vi.mocked(TestBed.inject(OwnedProductService).estimateAccuracyCandidates),
    ).toHaveBeenLastCalledWith('01JOWNED', 'Bosch GSR');
  });
});
