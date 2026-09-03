import { TestBed } from '@angular/core/testing';
import { of } from 'rxjs';

import { MarketReferenceService } from '../../../core/market-reference.service';
import {
  OwnedProduct,
  OwnedProductAssessment,
  SalePortfolioProjection,
  SellListingDraft,
} from '../../../core/owned-product.models';
import { OwnedProductService } from '../../../core/owned-product.service';
import { OwnedProductSalePortfolioPanelComponent } from './owned-product-sale-portfolio-panel.component';

const portfolioAssessment: OwnedProductAssessment = {
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
  confidence_basis_points: 9000,
  completeness_basis_points: 9500,
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
  included_accessories: [],
  missing_accessories: [],
  defects: [],
  candidates: [],
  reason_codes: [],
  unknown_facts: [],
  verification_actions: [],
  assessed_at: '2026-07-26T12:00:00Z',
  created_at: '2026-07-26T12:00:00Z',
};

const portfolioProduct: OwnedProduct = {
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
  target_countries: [
    { code: 'DE', name: 'Germany', currency_code: 'EUR' },
  ],
  target_country_codes: ['DE'],
  cross_border_preference: 'domestic_only',
  desired_sale_speed: 'balanced',
  status: 'ready',
  notes: null,
  image_count: 4,
  snapshot_count: 1,
  assessment_count: 1,
  current_assessment: portfolioAssessment,
  assessments: [portfolioAssessment],
  created_at: '2026-07-26T11:00:00Z',
  updated_at: '2026-07-26T11:00:00Z',
};

const portfolioDraft: SellListingDraft = {
  id: '01JDRAFT',
  owned_product_assessment_id: '01JASSESSMENT',
  sell_price_band_id: '01JBAND',
  run_number: 1,
  status: 'ready',
  photo_readiness_status: 'ready',
  photo_readiness_basis_points: 10000,
  listing_language: 'en',
  template_version: 'sell-listing-template:en:v1',
  generator_version: 'deterministic-sell-listing-generator:v1',
  photo_evaluator_version: 'deterministic-photo-readiness:v1',
  input_hash: 'c'.repeat(64),
  generated_at: '2026-07-26T13:00:00Z',
  target_country_code: 'DE',
  target_currency_code: 'EUR',
  price_strategy: 'recommended',
  target_asking_price_minor: 22000,
  selected_band: { low_minor: 20000, high_minor: 24000 },
  price_override_reason: null,
  title: 'Bosch GSR — used, good condition',
  description: 'For sale: Bosch GSR.',
  completeness_basis_points: 10000,
  reason_codes: [],
  unknown_facts: [],
  warnings: [],
  verification_actions: [],
  source_fact_identifiers: [],
  facts: [],
  photo_checklist: [],
  created_at: '2026-07-26T13:00:00Z',
};

const portfolioProjection: SalePortfolioProjection = {
  assessment_current: true,
  available_listing_drafts: [portfolioDraft],
  current_entry_id: '01JENTRY',
  entries: [
    {
      id: '01JENTRY',
      owned_product_id: '01JOWNED',
      sell_listing_draft_id: '01JDRAFT',
      sequence: 1,
      source_evidence_current: true,
      current_status: 'draft',
      current_event_id: null,
      allowed_events: ['published'],
      listing_draft_input_hash: 'c'.repeat(64),
      target_country_code: 'DE',
      target_currency_code: 'EUR',
      listing_language: 'en',
      price_strategy: 'recommended',
      initial_asking_price_minor: 22000,
      listing_title: 'Bosch GSR — used, good condition',
      current_event: null,
      events: [],
      entered_at: '2026-07-26T13:10:00Z',
      created_at: '2026-07-26T13:10:00Z',
    },
  ],
};

describe('OwnedProductSalePortfolioPanelComponent', () => {
  it('renders traceable portfolio state without preselecting publication facts', () => {
    TestBed.configureTestingModule({
      imports: [OwnedProductSalePortfolioPanelComponent],
      providers: [
        {
          provide: OwnedProductService,
          useValue: {
            salePortfolio: vi.fn().mockReturnValue(of(portfolioProjection)),
            createSalePortfolioEntry: vi.fn(),
            recordSalePortfolioEvent: vi.fn(),
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
      OwnedProductSalePortfolioPanelComponent,
    );
    fixture.componentRef.setInput('record', portfolioProduct);
    fixture.componentRef.setInput('canManage', true);
    fixture.detectChanges();
    const text = fixture.nativeElement.textContent as string;
    const draftSelect = fixture.nativeElement.querySelector(
      '#portfolio-draft',
    ) as HTMLSelectElement;
    const entrySelect = fixture.nativeElement.querySelector(
      '#portfolio-entry',
    ) as HTMLSelectElement;
    const eventSelect = fixture.nativeElement.querySelector(
      '#portfolio-event-type',
    ) as HTMLSelectElement;

    expect(text).toContain('Sale portfolio and publication history');
    expect(text).toContain('Bosch GSR — used, good condition');
    expect(text).toContain('Current source evidence');
    expect(draftSelect.value).toBe('');
    expect(entrySelect.value).toBe('');
    expect(eventSelect.value).toBe('');
    expect(
      fixture.nativeElement.querySelector('#portfolio-external-url'),
    ).toBeNull();

    entrySelect.value = '01JENTRY';
    entrySelect.dispatchEvent(new Event('change'));
    fixture.detectChanges();
    const refreshedEventSelect = fixture.nativeElement.querySelector(
      '#portfolio-event-type',
    ) as HTMLSelectElement;
    refreshedEventSelect.value = 'published';
    refreshedEventSelect.dispatchEvent(new Event('change'));
    fixture.detectChanges();

    expect(
      fixture.nativeElement.querySelector('#portfolio-external-url'),
    ).not.toBeNull();
    expect(
      fixture.nativeElement.querySelector('#portfolio-advertised-price'),
    ).not.toBeNull();
  });
});
