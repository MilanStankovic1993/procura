import { TestBed } from '@angular/core/testing';
import { of } from 'rxjs';

import { MarketReferenceService } from '../../../core/market-reference.service';
import {
  OwnedProduct,
  OwnedProductAssessment,
  SellPriceIntelligence,
} from '../../../core/owned-product.models';
import { OwnedProductService } from '../../../core/owned-product.service';
import { OwnedProductPriceIntelligencePanelComponent } from './owned-product-price-intelligence-panel.component';

const pricingAssessment: OwnedProductAssessment = {
  id: '01JASSESSMENT',
  run_number: 1,
  owned_product_snapshot_id: '01JSNAPSHOT',
  snapshot_sequence: 1,
  status: 'ready',
  matcher_status: 'matched',
  review_status: 'not_required',
  method: 'exact_catalog_alias',
  matcher_version: 'catalog-alias-matcher:v2',
  evaluator_version: 'owned-product-assessor:v1',
  input_hash: 'a'.repeat(64),
  confidence_basis_points: 8500,
  completeness_basis_points: 9000,
  product: {
    id: '01JMODEL',
    canonical_key: 'bosch:gsr',
    category: 'Drills',
    brand: 'Bosch',
    model: 'GSR',
    model_number: 'GSR',
    variant_id: null,
    variant: null,
  },
  condition: 'used_good',
  included_accessories: ['charger'],
  missing_accessories: [],
  defects: [],
  candidates: [],
  reason_codes: ['exact_catalog_alias'],
  unknown_facts: [],
  verification_actions: [],
  assessed_at: '2026-07-26T12:00:00Z',
  created_at: '2026-07-26T12:00:00Z',
};

const pricingProduct: OwnedProduct = {
  id: '01JOWNED',
  category: null,
  brand_name: 'Bosch',
  model_name: 'GSR',
  condition: 'used_good',
  age_months: 18,
  accessories: ['charger'],
  defects: [],
  purchase_history_known: false,
  purchase_history: null,
  target_continent_code: 'EU',
  target_countries: [
    { code: 'DE', name: 'Germany', currency_code: 'EUR' },
  ],
  target_country_codes: ['DE'],
  cross_border_preference: 'domestic_only',
  desired_sale_speed: 'balanced',
  status: 'ready',
  notes: null,
  image_count: 0,
  snapshot_count: 1,
  assessment_count: 1,
  current_assessment: pricingAssessment,
  assessments: [pricingAssessment],
  created_at: '2026-07-26T11:00:00Z',
  updated_at: '2026-07-26T11:00:00Z',
};

const selection = {
  id: '01JSELECTION',
  owned_product_assessment_id: '01JASSESSMENT',
  run_number: 3,
  status: 'ready' as const,
  selector_version: 'deterministic-sell-comparable-selector:v1',
  input_hash: 'b'.repeat(64),
  target_country_code: 'DE',
  target_currency_code: 'EUR',
  candidate_count: 3,
  included_count: 3,
  excluded_count: 0,
  minimum_required: 3,
  reason_codes: ['same_market_currency_only'],
  items: [
    {
      id: '01JITEM',
      sell_comparable_record_id: '01JRECORD',
      decision: 'included' as const,
      rank: 1,
      score_basis_points: 9000,
      factor_scores: { exact_model: 3000 },
      reason_codes: ['exact_model'],
      evidence: {
        marketplace_name: 'Market',
        title: 'Bosch GSR',
        asking_price_minor: 22000,
        currency_code: 'EUR',
        country_code: 'DE',
        observed_at: '2026-07-25T12:00:00Z',
      },
    },
  ],
  created_at: '2026-07-26T12:00:00Z',
};

let pricingData: SellPriceIntelligence = {
  assessment_current: true,
  current_assessment_id: '01JASSESSMENT',
  records: [],
  selections: [selection],
  price_bands: [
    {
      id: '01JBAND',
      owned_product_assessment_id: '01JASSESSMENT',
      sell_comparable_selection_id: '01JSELECTION',
      run_number: 3,
      status: 'ready',
      algorithm_version: 'deterministic-sell-price-bands:v1',
      input_hash: 'c'.repeat(64),
      calculated_at: '2026-07-26T12:00:00Z',
      target_country_code: 'DE',
      target_currency_code: 'EUR',
      input_count: 3,
      included_count: 3,
      outlier_count: 0,
      bands: {
        quick_sale: { low_minor: 20000, high_minor: 22000 },
        recommended: { low_minor: 20000, high_minor: 24000 },
        ambitious: { low_minor: 22000, high_minor: 24000 },
      },
      statistics: {
        median_minor: 22000,
        weighted_median_minor: 22000,
        q1_minor: 20000,
        q3_minor: 24000,
        mad_minor: 2000,
        dispersion_basis_points: 1818,
      },
      confidence_basis_points: 7600,
      confidence_level: 'high',
      completeness_basis_points: 9000,
      confidence_components: { comparable_count: 1500 },
      reason_codes: ['data_derived_sell_bands'],
      unknown_facts: ['realized_transaction_prices'],
      verification_actions: ['confirm_realized_sale_outcomes'],
      selection,
      items: [],
      created_at: '2026-07-26T12:00:00Z',
    },
  ],
  current_price_bands: [],
};
pricingData = {
  ...pricingData,
  current_price_bands: pricingData.price_bands,
};

describe('OwnedProductPriceIntelligencePanelComponent', () => {
  it('renders all three current evidence-derived bands and explanations', () => {
    TestBed.configureTestingModule({
      imports: [OwnedProductPriceIntelligencePanelComponent],
      providers: [
        {
          provide: OwnedProductService,
          useValue: {
            sellPriceIntelligence: vi.fn().mockReturnValue(of(pricingData)),
            createSellComparable: vi.fn(),
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
    const fixture = TestBed.createComponent(
      OwnedProductPriceIntelligencePanelComponent,
    );
    fixture.componentRef.setInput('record', pricingProduct);
    fixture.componentRef.setInput('canManage', true);
    fixture.detectChanges();
    const text = fixture.nativeElement.textContent as string;

    expect(text).toContain('Quick-sale band');
    expect(text).toContain('Recommended market band');
    expect(text).toContain('Ambitious band');
    expect(text).toContain('€200.00');
    expect(text).toContain('All three bands are derived');
    expect(text).toContain('Realized transaction prices');
  });

  it('keeps historical runs visible when the assessment is stale', () => {
    const staleData: SellPriceIntelligence = {
      ...pricingData,
      assessment_current: false,
      current_assessment_id: null,
      current_price_bands: [],
    };
    TestBed.configureTestingModule({
      imports: [OwnedProductPriceIntelligencePanelComponent],
      providers: [
        {
          provide: OwnedProductService,
          useValue: {
            sellPriceIntelligence: vi.fn().mockReturnValue(of(staleData)),
          },
        },
        {
          provide: MarketReferenceService,
          useValue: {
            catalog: vi.fn().mockReturnValue(
              of({ version: 'test', continents: [], currencies: [] }),
            ),
          },
        },
      ],
    });
    const fixture = TestBed.createComponent(
      OwnedProductPriceIntelligencePanelComponent,
    );
    fixture.componentRef.setInput('record', {
      ...pricingProduct,
      current_assessment: null,
    });
    fixture.detectChanges();

    expect(fixture.nativeElement.textContent).toContain(
      'The price result is historical',
    );
    expect(fixture.nativeElement.textContent).toContain(
      'Price-band history · 1 runs',
    );
  });
});
