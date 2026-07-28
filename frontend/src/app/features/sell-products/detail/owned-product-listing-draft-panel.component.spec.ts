import { TestBed } from '@angular/core/testing';
import { of } from 'rxjs';

import { MarketReferenceService } from '../../../core/market-reference.service';
import {
  OwnedProduct,
  OwnedProductAssessment,
  SellListingDraftProjection,
  SellPriceBand,
} from '../../../core/owned-product.models';
import { OwnedProductService } from '../../../core/owned-product.service';
import { OwnedProductListingDraftPanelComponent } from './owned-product-listing-draft-panel.component';

const draftAssessment: OwnedProductAssessment = {
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
  reason_codes: ['exact_catalog_alias'],
  unknown_facts: [],
  verification_actions: [],
  assessed_at: '2026-07-26T12:00:00Z',
  created_at: '2026-07-26T12:00:00Z',
};

const draftProduct: OwnedProduct = {
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
  current_assessment: draftAssessment,
  assessments: [draftAssessment],
  created_at: '2026-07-26T11:00:00Z',
  updated_at: '2026-07-26T11:00:00Z',
};

const draftBand: SellPriceBand = {
  id: '01JBAND',
  owned_product_assessment_id: '01JASSESSMENT',
  sell_comparable_selection_id: '01JSELECTION',
  run_number: 3,
  status: 'ready',
  algorithm_version: 'deterministic-sell-price-bands:v1',
  input_hash: 'b'.repeat(64),
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
  confidence_basis_points: 8000,
  confidence_level: 'high',
  completeness_basis_points: 9000,
  confidence_components: {},
  reason_codes: [],
  unknown_facts: [],
  verification_actions: [],
  items: [],
  created_at: '2026-07-26T12:00:00Z',
};

const draftProjectionBase: SellListingDraftProjection = {
  assessment_current: true,
  current_assessment_id: '01JASSESSMENT',
  available_price_bands: [draftBand],
  current_drafts: [
    {
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
      description:
        'For sale: Bosch GSR.\n\nCondition\nCondition: used, good condition.',
      completeness_basis_points: 9500,
      reason_codes: ['source_facts_only'],
      unknown_facts: ['realized_sale_price'],
      warnings: ['asking_price_guidance_not_guarantee'],
      verification_actions: [],
      source_fact_identifiers: [],
      facts: [
        {
          id: '01JFACT',
          position: 1,
          fact_code: 'identity',
          source_kind: 'owned_product_assessment',
          source_id: '01JASSESSMENT',
          source_field: 'identified_product',
          is_unknown: false,
          disclosure: 'For sale: Bosch GSR.',
          value: {},
        },
      ],
      photo_checklist: [
        {
          id: '01JCHECK',
          position: 1,
          check_code: 'product_overview',
          status: 'satisfied',
          required: true,
          image_kind: 'product',
          minimum_count: 1,
          observed_count: 3,
          matching_image_ids: [],
          reason_codes: [],
          verification_actions: [],
        },
      ],
      created_at: '2026-07-26T13:00:00Z',
    },
  ],
  drafts: [],
};
const draftProjection: SellListingDraftProjection = {
  ...draftProjectionBase,
  drafts: draftProjectionBase.current_drafts,
};

describe('OwnedProductListingDraftPanelComponent', () => {
  it('renders exact generated copy and photo readiness without preselecting user choices', () => {
    TestBed.configureTestingModule({
      imports: [OwnedProductListingDraftPanelComponent],
      providers: [
        {
          provide: OwnedProductService,
          useValue: {
            sellListingDrafts: vi.fn().mockReturnValue(of(draftProjection)),
            createSellListingDraft: vi.fn(),
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
      OwnedProductListingDraftPanelComponent,
    );
    fixture.componentRef.setInput('record', draftProduct);
    fixture.componentRef.setInput('canManage', true);
    fixture.detectChanges();
    const text = fixture.nativeElement.textContent as string;
    const selects = fixture.nativeElement.querySelectorAll(
      '#listing-price-band, #listing-strategy, #listing-language',
    ) as NodeListOf<HTMLSelectElement>;

    expect(text).toContain('Listing draft and photo readiness');
    expect(text).toContain('Bosch GSR — used, good condition');
    expect(text).toContain('For sale: Bosch GSR.');
    expect(text).toContain('Clear product overview');
    expect(text).toContain('Realized sale price');
    expect(selects[0]?.value).toBe('');
    expect(selects[1]?.value).toBe('');
    expect(selects[2]?.value).toBe('');
  });

  it('keeps historical drafts visible when upstream assessment evidence is stale', () => {
    const staleProjection: SellListingDraftProjection = {
      ...draftProjection,
      assessment_current: false,
      current_assessment_id: null,
      available_price_bands: [],
      current_drafts: [],
      drafts: draftProjection.drafts,
    };
    TestBed.configureTestingModule({
      imports: [OwnedProductListingDraftPanelComponent],
      providers: [
        {
          provide: OwnedProductService,
          useValue: {
            sellListingDrafts: vi
              .fn()
              .mockReturnValue(of(staleProjection)),
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
      OwnedProductListingDraftPanelComponent,
    );
    fixture.componentRef.setInput('record', {
      ...draftProduct,
      current_assessment: null,
    });
    fixture.detectChanges();

    expect(fixture.nativeElement.textContent).toContain(
      'Upstream evidence is historical',
    );
    expect(fixture.nativeElement.textContent).toContain(
      'Draft history · 1 runs',
    );
  });
});
