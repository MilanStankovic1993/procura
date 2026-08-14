import {
  nextSellJourneyStage,
  SellJourneySnapshot,
} from './sell-journey-state';

const completeUpstream: SellJourneySnapshot = {
  productStatus: 'ready',
  assessmentStatus: 'ready',
  pricing: 'complete',
  listing: 'complete',
  portfolio: 'complete',
  outcome: 'complete',
};

describe('nextSellJourneyStage', () => {
  it('starts with product preparation and respects archived records', () => {
    expect(
      nextSellJourneyStage({ ...completeUpstream, productStatus: 'draft' }),
    ).toBe('prepare');
    expect(
      nextSellJourneyStage({ ...completeUpstream, productStatus: 'archived' }),
    ).toBe('archived');
  });

  it('keeps incomplete product identification ahead of downstream projections', () => {
    expect(
      nextSellJourneyStage({
        ...completeUpstream,
        assessmentStatus: 'needs_input',
      }),
    ).toBe('assessment');
  });

  it('selects the first incomplete commercial step in order', () => {
    expect(
      nextSellJourneyStage({ ...completeUpstream, pricing: 'ready' }),
    ).toBe('pricing');
    expect(
      nextSellJourneyStage({ ...completeUpstream, listing: 'ready' }),
    ).toBe('listing');
    expect(
      nextSellJourneyStage({ ...completeUpstream, portfolio: 'ready' }),
    ).toBe('portfolio');
  });

  it('moves from marketplace tracking to outcome completion', () => {
    expect(
      nextSellJourneyStage({ ...completeUpstream, outcome: 'ready' }),
    ).toBe('outcome');
    expect(nextSellJourneyStage(completeUpstream)).toBe('complete');
  });
});
