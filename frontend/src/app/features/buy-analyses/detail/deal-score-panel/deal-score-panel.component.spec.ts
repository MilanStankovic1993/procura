import { TestBed } from '@angular/core/testing';

import { DealScore } from '../../../../core/analysis.models';
import { DealScorePanelComponent } from './deal-score-panel.component';

const record: DealScore = {
  id: '01deal',
  run_number: 1,
  product_match_id: '01match',
  price_estimate_id: '01price',
  risk_assessment_id: '01risk',
  profit_estimate_id: '01profit',
  opportunity_input_id: '01input',
  logistics_assessment_id: '01logistics',
  demand_assessment_id: '01demand',
  status: 'assessed',
  calculation_version: 'deterministic-deal-score:v1',
  input_hash: 'hash',
  calculated_at: '2026-07-25T20:00:00Z',
  uncapped_score: 76,
  uncapped_score_basis_points: 7600,
  score: 60,
  score_basis_points: 6000,
  recommendation: 'needs_verification',
  confidence_basis_points: 7450,
  confidence_level: 'medium',
  unknown_count: 0,
  applicable_cap: 60,
  cap_decisions: [
    {
      code: 'low_price_confidence',
      maximum_score: 60,
      triggered: true,
      applied: true,
      evidence: {},
    },
  ],
  reason_codes: ['low_price_confidence_cap_applied'],
  confidence_components: { estimated_net_margin: 2500 },
  factors_increasing: ['resale_demand_strengthens_score'],
  factors_reducing: ['low_price_confidence_cap_reduces_score'],
  assumptions: ['expected_sale_price_is_realized'],
  verification_actions: ['Add recent, diverse comparables.'],
  items: [
    {
      id: '01item',
      position: 1,
      component: 'estimated_net_margin',
      weight_basis_points: 3500,
      raw_value: 2000,
      raw_value_unit: 'basis_points',
      normalized_score_basis_points: 5000,
      weighted_contribution_basis_points: 1750,
      confidence_basis_points: 8000,
      impact: 'neutral',
      source: {},
    },
  ],
  created_at: '2026-07-25T20:00:00Z',
};

describe('DealScorePanelComponent', () => {
  it('renders the capped score, component evidence and next checks', async () => {
    await TestBed.configureTestingModule({
      imports: [DealScorePanelComponent],
    }).compileComponents();
    const fixture = TestBed.createComponent(DealScorePanelComponent);
    fixture.componentRef.setInput('record', record);
    fixture.detectChanges();
    const text = fixture.nativeElement.textContent as string;

    expect(text).toContain('60 / 100');
    expect(text).toContain('76 / 100');
    expect(text).toContain('Needs verification');
    expect(text).toContain('Estimated net margin');
    expect(text).toContain('35.00% weight');
    expect(text).toContain('Low Price Confidence');
    expect(text).toContain('Applied');
    expect(text).toContain('Add recent, diverse comparables.');
    expect(text).toContain('does not guarantee');
  });
});
