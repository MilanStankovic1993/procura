import { TestBed } from '@angular/core/testing';
import { of } from 'rxjs';

import {
  OwnedProduct,
  OwnedProductAssessment,
} from '../../../core/owned-product.models';
import { OwnedProductService } from '../../../core/owned-product.service';
import { OwnedProductAssessmentPanelComponent } from './owned-product-assessment-panel.component';

const assessment: OwnedProductAssessment = {
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
  product: null,
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

function product(
  overrides: Partial<OwnedProduct> = {},
): OwnedProduct {
  return {
    id: '01JOWNED',
    category: null,
    brand_name: 'Bosch Professional',
    model_name: 'GSR 18V-55',
    condition: 'used_good',
    age_months: 18,
    accessories: ['charger'],
    defects: [],
    purchase_history_known: false,
    purchase_history: null,
    target_continent_code: 'EU',
    target_countries: [],
    target_country_codes: ['DE'],
    cross_border_preference: 'cross_border_allowed',
    desired_sale_speed: 'balanced',
    status: 'ready',
    notes: null,
    image_count: 0,
    snapshot_count: 1,
    assessment_count: 0,
    images: [],
    snapshots: [
      {
        id: '01JSNAPSHOT',
        sequence: 1,
        captured_at: '2026-07-26T11:00:00Z',
        category: null,
        brand_name: 'Bosch Professional',
        model_name: 'GSR 18V-55',
        condition: 'used_good',
        age_months: 18,
        accessories: ['charger'],
        defects: [],
        purchase_history_known: false,
        purchase_history: null,
        target_continent_code: 'EU',
        target_country_codes: ['DE'],
        cross_border_preference: 'cross_border_allowed',
        desired_sale_speed: 'balanced',
        status: 'ready',
        notes: null,
        content_hash: 'b'.repeat(64),
      },
    ],
    assessments: [],
    current_assessment: null,
    created_at: '2026-07-26T11:00:00Z',
    updated_at: '2026-07-26T11:00:00Z',
    ...overrides,
  };
}

describe('OwnedProductAssessmentPanelComponent', () => {
  it('assesses the exact latest snapshot and asks the parent to refresh', () => {
    const service = {
      assess: vi.fn().mockReturnValue(of(assessment)),
    };
    TestBed.configureTestingModule({
      imports: [OwnedProductAssessmentPanelComponent],
      providers: [{ provide: OwnedProductService, useValue: service }],
    });
    const fixture = TestBed.createComponent(
      OwnedProductAssessmentPanelComponent,
    );
    fixture.componentRef.setInput('record', product());
    fixture.componentRef.setInput('canManage', true);
    const emitted = vi.fn();
    fixture.componentInstance.assessmentRecorded.subscribe(emitted);
    fixture.detectChanges();

    (
      fixture.nativeElement.querySelector('button') as HTMLButtonElement
    ).click();

    expect(service.assess).toHaveBeenCalledWith('01JOWNED', '01JSNAPSHOT');
    expect(emitted).toHaveBeenCalledOnce();
  });

  it('labels an assessment as historical when current evidence changed', () => {
    TestBed.configureTestingModule({
      imports: [OwnedProductAssessmentPanelComponent],
      providers: [
        {
          provide: OwnedProductService,
          useValue: { assess: vi.fn() },
        },
      ],
    });
    const fixture = TestBed.createComponent(
      OwnedProductAssessmentPanelComponent,
    );
    fixture.componentRef.setInput(
      'record',
      product({
        assessment_count: 1,
        assessments: [assessment],
        current_assessment: null,
      }),
    );
    fixture.detectChanges();

    expect(fixture.nativeElement.textContent).toContain(
      'The latest assessment is historical',
    );
    expect(fixture.nativeElement.textContent).toContain(
      'Historical result shown',
    );
  });
});
