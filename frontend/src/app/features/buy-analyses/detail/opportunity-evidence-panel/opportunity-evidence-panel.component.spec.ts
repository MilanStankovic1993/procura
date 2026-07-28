import { TestBed } from '@angular/core/testing';
import { of } from 'rxjs';

import {
  Analysis,
  ComparableSet,
  CostInput,
  OpportunityAssessment,
  OpportunityEvidenceSubmission,
  OpportunityInput,
  PriceEstimate,
  ProfitEstimate,
  RiskAssessment,
} from '../../../../core/analysis.models';
import { AnalysisService } from '../../../../core/analysis.service';
import { OpportunityEvidencePanelComponent } from './opportunity-evidence-panel.component';

class AnalysisServiceStub {
  lastInput: OpportunityEvidenceSubmission | null = null;

  confirmOpportunityEvidence(
    _analysisId: string,
    input: OpportunityEvidenceSubmission,
  ) {
    this.lastInput = input;

    return of({
      analysis: {} as Analysis,
      created: true,
    });
  }
}

const opportunityInput = {
  id: '01JOPPORTUNITYINPUT0000001',
  run_number: 1,
  comparable_set_id: '01JCOMPARABLESET0000000001',
  price_estimate_id: '01JPRICEESTIMATE00000000001',
  risk_assessment_id: '01JRISKASSESSMENT000000001',
  cost_input_id: '01JCOSTINPUT00000000000001',
  profit_estimate_id: '01JPROFITESTIMATE000000001',
  submitted_by_user_id: 1,
  input_version: 'explicit-opportunity-evidence:v1',
  input_hash: 'a'.repeat(64),
  source_country_code: 'DE',
  target_country_code: 'DE',
  known_count: 12,
  unknown_count: 0,
  items: [
    {
      id: '01JOPPORTUNITYITEM0000001',
      component: 'logistics',
      position: 1,
      code: 'shipping_method',
      value_type: 'string',
      value: 'parcel',
      is_known: true,
      is_required: true,
      source: 'user_confirmed',
      evidence: {},
    },
    {
      id: '01JOPPORTUNITYITEM0000002',
      component: 'logistics',
      position: 2,
      code: 'pickup_available',
      value_type: 'boolean',
      value: false,
      is_known: true,
      is_required: true,
      source: 'user_confirmed',
      evidence: {},
    },
    {
      id: '01JOPPORTUNITYITEM0000003',
      component: 'demand',
      position: 1,
      code: 'sold_comparables_count',
      value_type: 'integer',
      value: 0,
      is_known: true,
      is_required: true,
      source: 'user_confirmed',
      evidence: {},
    },
  ],
  submitted_at: '2026-07-25T12:10:00Z',
  created_at: '2026-07-25T12:10:00Z',
} satisfies OpportunityInput;

const logisticsAssessment = {
  id: '01JLOGISTICSASSESSMENT0001',
  run_number: 1,
  opportunity_input_id: opportunityInput.id,
  profit_estimate_id: opportunityInput.profit_estimate_id,
  component: 'logistics',
  status: 'assessed',
  score: 83,
  confidence_basis_points: 8500,
  confidence_level: 'high',
  unknown_count: 0,
  evaluator_version: 'deterministic-logistics-simplicity:v1',
  input_hash: 'b'.repeat(64),
  calculated_at: '2026-07-25T12:10:00Z',
  reason_codes: ['domestic_logistics_scope'],
  confidence_components: { explicit_evidence: 6000 },
  items: [
    {
      id: '01JLOGISTICSITEM000000001',
      opportunity_input_item_id: opportunityInput.items[0].id,
      position: 1,
      code: 'shipping_method_simplicity',
      maximum_points: 20,
      score_contribution: 16,
      is_known: true,
      source: {},
    },
  ],
  created_at: '2026-07-25T12:10:00Z',
} satisfies OpportunityAssessment;

const demandAssessment = {
  id: '01JDEMANDASSESSMENT000001',
  run_number: 1,
  opportunity_input_id: opportunityInput.id,
  profit_estimate_id: opportunityInput.profit_estimate_id,
  component: 'demand',
  status: 'assessed',
  score: 30,
  confidence_basis_points: 7600,
  confidence_level: 'high',
  unknown_count: 0,
  evaluator_version: 'deterministic-resale-demand:v1',
  input_hash: 'c'.repeat(64),
  calculated_at: '2026-07-25T12:10:00Z',
  reason_codes: [
    'asking_comparables_do_not_prove_sales',
    'no_sold_comparables_observed',
  ],
  confidence_components: { sale_evidence_completeness: 4000 },
  items: [
    {
      id: '01JDEMANDITEM00000000001',
      opportunity_input_item_id: opportunityInput.items[2].id,
      position: 4,
      code: 'observed_sale_velocity',
      maximum_points: 30,
      score_contribution: 0,
      is_known: true,
      source: {},
    },
  ],
  created_at: '2026-07-25T12:10:00Z',
} satisfies OpportunityAssessment;

describe('OpportunityEvidencePanelComponent', () => {
  it('renders independent scores and serializes known false and zero separately from unknown', async () => {
    const service = new AnalysisServiceStub();
    TestBed.configureTestingModule({
      imports: [OpportunityEvidencePanelComponent],
      providers: [{ provide: AnalysisService, useValue: service }],
    });
    const fixture = TestBed.createComponent(OpportunityEvidencePanelComponent);
    fixture.componentRef.setInput('analysisId', '01JANALYSIS00000000000001');
    fixture.componentRef.setInput('sourceCountryCode', 'DE');
    fixture.componentRef.setInput('targetCountryCode', 'DE');
    fixture.componentRef.setInput('comparableSet', {
      id: '01JCOMPARABLESET0000000001',
    } as ComparableSet);
    fixture.componentRef.setInput('priceEstimate', {
      id: '01JPRICEESTIMATE00000000001',
    } as PriceEstimate);
    fixture.componentRef.setInput('riskAssessment', {
      id: '01JRISKASSESSMENT000000001',
    } as RiskAssessment);
    fixture.componentRef.setInput('costInput', {
      id: '01JCOSTINPUT00000000000001',
    } as CostInput);
    fixture.componentRef.setInput('profitEstimate', {
      id: '01JPROFITESTIMATE000000001',
    } as ProfitEstimate);
    fixture.componentRef.setInput('opportunityInput', opportunityInput);
    fixture.componentRef.setInput('logisticsAssessment', logisticsAssessment);
    fixture.componentRef.setInput('demandAssessment', demandAssessment);
    fixture.componentRef.setInput('canManage', true);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();

    expect(fixture.nativeElement.textContent).toContain('Logistics & resale demand');
    expect(fixture.nativeElement.textContent).toContain('83 / 100');
    expect(fixture.nativeElement.textContent).toContain('30 / 100');
    expect(fixture.nativeElement.textContent).toContain(
      'Asking Comparables Do Not Prove Sales',
    );

    const pickup = fixture.nativeElement.querySelector(
      '[formcontrolname="pickup_available"]',
    ) as HTMLSelectElement;
    const soldCount = fixture.nativeElement.querySelector(
      '[formcontrolname="sold_comparables_count"]',
    ) as HTMLInputElement;
    const distance = fixture.nativeElement.querySelector(
      '[formcontrolname="shipping_distance_km"]',
    ) as HTMLInputElement;
    expect(pickup.value).toBe('false');
    expect(soldCount.value).toBe('0');
    distance.value = '';
    distance.dispatchEvent(new Event('input'));
    fixture.nativeElement
      .querySelector('form')
      .dispatchEvent(new Event('submit'));
    fixture.detectChanges();

    expect(service.lastInput?.pickup_available).toBe(false);
    expect(service.lastInput?.sold_comparables_count).toBe(0);
    expect(service.lastInput?.median_days_to_sale).toBeNull();
    expect(service.lastInput?.shipping_distance_km).toBeNull();
  });
});
