import { HttpErrorResponse } from '@angular/common/http';
import { TestBed } from '@angular/core/testing';
import { throwError } from 'rxjs';

import {
  BuyerDecisionEvent,
  BuyerDecisionSubmission,
  DealScore,
} from '../../../../core/analysis.models';
import { AnalysisService } from '../../../../core/analysis.service';
import { BuyerDecisionPanelComponent } from './buyer-decision-panel.component';

class AnalysisServiceStub {
  readonly submissions: BuyerDecisionSubmission[] = [];

  recordBuyerDecision(_analysisId: string, input: BuyerDecisionSubmission) {
    this.submissions.push(input);

    return throwError(
      () =>
        new HttpErrorResponse({
          status: 409,
          error: {
            message: 'The buyer decision changed.',
          },
        }),
    );
  }
}

const dealScore: DealScore = {
  id: '01JDEALSCORE00000000000001',
  run_number: 2,
  product_match_id: '01JPRODUCTMATCH00000000001',
  price_estimate_id: '01JPRICEESTIMATE00000000001',
  risk_assessment_id: '01JRISKASSESSMENT000000001',
  profit_estimate_id: '01JPROFITESTIMATE000000001',
  opportunity_input_id: '01JOPPORTUNITYINPUT0000001',
  logistics_assessment_id: '01JLOGISTICS00000000000001',
  demand_assessment_id: '01JDEMAND0000000000000001',
  status: 'assessed',
  calculation_version: 'deterministic-deal-score:v1',
  input_hash: 'a'.repeat(64),
  calculated_at: '2026-07-26T10:00:00Z',
  uncapped_score: 70,
  uncapped_score_basis_points: 7000,
  score: 68,
  score_basis_points: 6800,
  recommendation: 'potential_opportunity',
  confidence_basis_points: 7438,
  confidence_level: 'medium',
  unknown_count: 0,
  applicable_cap: null,
  cap_decisions: [],
  reason_codes: [],
  confidence_components: {},
  factors_increasing: [],
  factors_reducing: [],
  assumptions: [],
  verification_actions: [],
  items: [],
  created_at: '2026-07-26T10:00:00Z',
};

const currentDecision: BuyerDecisionEvent = {
  id: '01JDECISION000000000000001',
  sequence: 1,
  analysis_id: '01JANALYSIS00000000000001',
  deal_score_id: dealScore.id,
  previous_event_id: null,
  prior_state: null,
  next_state: 'interested',
  reason_code: 'margin_reviewed',
  note: 'Commercial review completed.',
  actor: {
    id: 10,
    name: 'Workspace Owner',
  },
  deal_score: {
    id: dealScore.id,
    run_number: dealScore.run_number,
    score: dealScore.score,
    recommendation: dealScore.recommendation,
  },
  decided_at: '2026-07-26T10:05:00Z',
  created_at: '2026-07-26T10:05:00Z',
};

describe('BuyerDecisionPanelComponent', () => {
  it('renders the current exact-score decision, history and financial boundary', () => {
    TestBed.configureTestingModule({
      imports: [BuyerDecisionPanelComponent],
      providers: [
        {
          provide: AnalysisService,
          useValue: new AnalysisServiceStub(),
        },
      ],
    });
    const fixture = TestBed.createComponent(BuyerDecisionPanelComponent);
    fixture.componentRef.setInput('analysisId', currentDecision.analysis_id);
    fixture.componentRef.setInput('dealScore', dealScore);
    fixture.componentRef.setInput('currentDecision', currentDecision);
    fixture.componentRef.setInput('allowedTransitions', [
      'contacted',
      'purchased',
      'rejected',
      'archived',
    ]);
    fixture.componentRef.setInput('history', [currentDecision]);
    fixture.componentRef.setInput('historyCount', 1);
    fixture.componentRef.setInput('canManage', true);
    fixture.detectChanges();
    const text = fixture.nativeElement.textContent as string;

    expect(text).toContain('Decision status');
    expect(text).toContain('Interested');
    expect(text).toContain('Workspace Owner');
    expect(text).toContain('Score run #2');
    expect(text).toContain('Commercial review completed.');
    expect(text).toContain('does not create a purchase');
    expect(text).toContain('Showing 1 of 1 events');
  });

  it('reuses the idempotency key when an unchanged failed command is retried', () => {
    const service = new AnalysisServiceStub();
    TestBed.configureTestingModule({
      imports: [BuyerDecisionPanelComponent],
      providers: [{ provide: AnalysisService, useValue: service }],
    });
    const fixture = TestBed.createComponent(BuyerDecisionPanelComponent);
    fixture.componentRef.setInput('analysisId', currentDecision.analysis_id);
    fixture.componentRef.setInput('dealScore', dealScore);
    fixture.componentRef.setInput('currentDecision', currentDecision);
    fixture.componentRef.setInput('allowedTransitions', ['contacted', 'rejected']);
    fixture.componentRef.setInput('history', [currentDecision]);
    fixture.componentRef.setInput('historyCount', 1);
    fixture.componentRef.setInput('canManage', true);
    fixture.detectChanges();

    const state = fixture.nativeElement.querySelector(
      '[formcontrolname="next_state"]',
    ) as HTMLSelectElement;
    const reason = fixture.nativeElement.querySelector(
      '[formcontrolname="reason_code"]',
    ) as HTMLInputElement;
    state.value = 'contacted';
    state.dispatchEvent(new Event('change'));
    reason.value = 'seller_contacted';
    reason.dispatchEvent(new Event('input'));
    const form = fixture.nativeElement.querySelector('form') as HTMLFormElement;
    form.dispatchEvent(new Event('submit'));
    form.dispatchEvent(new Event('submit'));

    expect(service.submissions).toHaveLength(2);
    expect(service.submissions[0].expected_current_event_id).toBe(currentDecision.id);
    expect(service.submissions[0].next_state).toBe('contacted');
    expect(service.submissions[0].reason_code).toBe('seller_contacted');
    expect(service.submissions[0].idempotency_key).toBe(
      service.submissions[1].idempotency_key,
    );
  });
});
