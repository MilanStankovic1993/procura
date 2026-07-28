import { TestBed } from '@angular/core/testing';
import { of } from 'rxjs';

import {
  Analysis,
  CostInput,
  CostInputSubmission,
  PriceEstimate,
  ProfitEstimate,
  RiskAssessment,
} from '../../../../core/analysis.models';
import { AnalysisService } from '../../../../core/analysis.service';
import { CostProfitPanelComponent } from './cost-profit-panel.component';

class AnalysisServiceStub {
  lastInput: CostInputSubmission | null = null;

  confirmCosts(_analysisId: string, input: CostInputSubmission) {
    this.lastInput = input;

    return of({
      analysis: {} as Analysis,
      created: true,
    });
  }
}

const priceEstimate: PriceEstimate = {
  id: '01JPRICEESTIMATE00000000001',
  run_number: 1,
  comparable_set_id: '01JCOMPARABLESET0000000001',
  status: 'estimated',
  algorithm_version: 'deterministic-weighted-median:v1',
  rate_resolver_version: 'dated-exchange-rate-resolver:v1',
  input_hash: 'a'.repeat(64),
  calculation_at: '2026-07-25T12:00:00Z',
  target_country_code: 'DE',
  target_currency_code: 'EUR',
  input_count: 3,
  included_count: 3,
  outlier_count: 0,
  unresolved_count: 0,
  estimate_low_minor: 21000,
  estimate_minor: 22000,
  estimate_high_minor: 23000,
  statistics: {
    median_minor: 22000,
    weighted_median_minor: 22000,
    q1_minor: 21000,
    q3_minor: 23000,
    mad_minor: 1000,
    dispersion_basis_points: 909,
  },
  confidence_basis_points: 7200,
  confidence_level: 'medium',
  confidence_components: {},
  reason_codes: [],
  items: [],
  created_at: '2026-07-25T12:00:00Z',
};

const riskAssessment: RiskAssessment = {
  id: '01JRISKASSESSMENT000000001',
  run_number: 1,
  product_match_id: '01JPRODUCTMATCH00000000001',
  comparable_set_id: '01JCOMPARABLESET0000000001',
  price_estimate_id: priceEstimate.id,
  status: 'assessed',
  evaluator_version: 'deterministic-risk-evaluator:v1',
  input_hash: 'b'.repeat(64),
  calculation_at: '2026-07-25T12:00:00Z',
  score: 5,
  level: 'low',
  confidence_basis_points: 7000,
  confidence_level: 'medium',
  signal_count: 1,
  unknown_count: 0,
  reason_codes: [],
  confidence_components: {},
  verification_actions: [],
  signals: [],
  created_at: '2026-07-25T12:00:00Z',
};

const costInput: CostInput = {
  id: '01JCOSTINPUT00000000000001',
  run_number: 1,
  price_estimate_id: priceEstimate.id,
  risk_assessment_id: riskAssessment.id,
  submitted_by_user_id: 1,
  input_version: 'explicit-cost-input:v1',
  input_hash: 'c'.repeat(64),
  currency_code: 'EUR',
  source_country_code: 'DE',
  target_country_code: 'DE',
  regional_compatibility_confirmed: null,
  known_count: 9,
  unknown_count: 0,
  items: [
    {
      id: '01JCOSTITEM000000000000001',
      position: 1,
      category: 'purchase_price',
      amount_minor: 15000,
      is_known: true,
      source: 'user_confirmed',
    },
  ],
  submitted_at: '2026-07-25T12:05:00Z',
  created_at: '2026-07-25T12:05:00Z',
};

const profitEstimate: ProfitEstimate = {
  id: '01JPROFITESTIMATE000000001',
  run_number: 1,
  price_estimate_id: priceEstimate.id,
  risk_assessment_id: riskAssessment.id,
  cost_input_id: costInput.id,
  status: 'estimated',
  calculation_version: 'deterministic-expected-profit:v1',
  input_hash: 'd'.repeat(64),
  calculation_at: '2026-07-25T12:05:00Z',
  currency_code: 'EUR',
  expected_sale_price_minor: 22000,
  purchase_price_minor: 15000,
  gross_margin_minor: 7000,
  known_costs_minor: 19700,
  additional_costs_minor: 4700,
  total_cost_minor: 19700,
  expected_net_profit_minor: 2300,
  profit_margin_basis_points: 1045,
  return_on_invested_capital_basis_points: 1168,
  confidence_basis_points: 7850,
  confidence_level: 'high',
  unknown_count: 0,
  reason_codes: [
    'all_cost_inputs_confirmed',
    'expected_sale_price_from_price_estimate',
  ],
  confidence_components: {
    price_evidence: 3600,
    risk_evidence: 1750,
    cost_completeness: 2000,
    market_scope_confirmation: 500,
  },
  items: [
    {
      id: '01JPROFITITEM0000000000001',
      cost_input_item_id: null,
      position: 1,
      category: 'expected_sale_price',
      kind: 'revenue',
      amount_minor: 22000,
      is_known: true,
      source: {},
    },
  ],
  created_at: '2026-07-25T12:05:00Z',
};

describe('CostProfitPanelComponent', () => {
  it('renders explainable metrics and preserves zero separately from unknown input', async () => {
    const service = new AnalysisServiceStub();
    TestBed.configureTestingModule({
      imports: [CostProfitPanelComponent],
      providers: [{ provide: AnalysisService, useValue: service }],
    });
    const fixture = TestBed.createComponent(CostProfitPanelComponent);
    fixture.componentRef.setInput('analysisId', '01JANALYSIS00000000000001');
    fixture.componentRef.setInput('sourceCountryCode', 'DE');
    fixture.componentRef.setInput('targetCountryCode', 'DE');
    fixture.componentRef.setInput('listingAskingPriceMinor', 15000);
    fixture.componentRef.setInput('listingCurrencyCode', 'EUR');
    fixture.componentRef.setInput('priceEstimate', priceEstimate);
    fixture.componentRef.setInput('riskAssessment', riskAssessment);
    fixture.componentRef.setInput('costInput', costInput);
    fixture.componentRef.setInput('profitEstimate', profitEstimate);
    fixture.componentRef.setInput('canManage', true);
    fixture.componentRef.setInput('catalog', {
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
    });
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();

    expect(fixture.nativeElement.textContent).toContain('Costs & expected profit');
    expect(fixture.nativeElement.textContent).toContain(
      'deterministic-expected-profit:v1',
    );
    expect(fixture.nativeElement.textContent).toContain('Expected net profit');
    expect(fixture.nativeElement.textContent).toContain(
      'All Cost Inputs Confirmed',
    );

    const purchase = fixture.nativeElement.querySelector(
      '[formcontrolname="purchase_price"]',
    ) as HTMLInputElement;
    const transport = fixture.nativeElement.querySelector(
      '[formcontrolname="transport"]',
    ) as HTMLInputElement;
    const repair = fixture.nativeElement.querySelector(
      '[formcontrolname="repair"]',
    ) as HTMLInputElement;
    purchase.value = '150.00';
    purchase.dispatchEvent(new Event('input'));
    transport.value = '0';
    transport.dispatchEvent(new Event('input'));
    repair.value = '';
    repair.dispatchEvent(new Event('input'));
    fixture.nativeElement
      .querySelector('form')
      .dispatchEvent(new Event('submit'));
    fixture.detectChanges();

    expect(service.lastInput?.purchase_price_minor).toBe(15000);
    expect(service.lastInput?.transport_minor).toBe(0);
    expect(service.lastInput?.repair_minor).toBeNull();
    expect(service.lastInput?.currency_code).toBe('EUR');
  });
});
