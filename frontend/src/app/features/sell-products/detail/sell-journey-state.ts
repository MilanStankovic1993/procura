export type SellJourneyPanelState =
  | 'loading'
  | 'error'
  | 'blocked'
  | 'ready'
  | 'complete';

export type SellJourneyStage =
  | 'prepare'
  | 'assessment'
  | 'pricing'
  | 'listing'
  | 'portfolio'
  | 'outcome'
  | 'complete'
  | 'archived';

export interface SellJourneySnapshot {
  readonly productStatus: 'draft' | 'ready' | 'archived';
  readonly assessmentStatus: 'ready' | 'needs_input' | 'review_required' | null;
  readonly pricing: SellJourneyPanelState;
  readonly listing: SellJourneyPanelState;
  readonly portfolio: SellJourneyPanelState;
  readonly outcome: SellJourneyPanelState;
}

export function nextSellJourneyStage(snapshot: SellJourneySnapshot): SellJourneyStage {
  if (snapshot.productStatus === 'draft') {
    return 'prepare';
  }

  if (snapshot.productStatus === 'archived') {
    return 'archived';
  }

  if (snapshot.assessmentStatus !== 'ready') {
    return 'assessment';
  }

  if (snapshot.pricing !== 'complete') {
    return 'pricing';
  }

  if (snapshot.listing !== 'complete') {
    return 'listing';
  }

  if (snapshot.portfolio !== 'complete') {
    return 'portfolio';
  }

  return snapshot.outcome === 'complete' ? 'complete' : 'outcome';
}
