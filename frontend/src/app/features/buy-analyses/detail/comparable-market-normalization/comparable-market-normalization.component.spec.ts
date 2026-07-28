import { TestBed } from '@angular/core/testing';
import { of } from 'rxjs';

import {
  ComparableMarketNormalizationInput,
  ComparableSetItem,
} from '../../../../core/analysis.models';
import { AnalysisService } from '../../../../core/analysis.service';
import { MarketReferenceCatalog } from '../../../../core/market.models';
import { ComparableMarketNormalizationComponent } from './comparable-market-normalization.component';

class AnalysisServiceStub {
  readonly submissions: {
    analysisId: string;
    comparableRecordId: string;
    input: ComparableMarketNormalizationInput;
  }[] = [];

  createMarketNormalization(
    analysisId: string,
    comparableRecordId: string,
    input: ComparableMarketNormalizationInput,
  ) {
    this.submissions.push({ analysisId, comparableRecordId, input });

    return of({
      created: true,
      normalization: {
        id: '01JNORMALIZATION',
        normalized_amount_minor: 21300,
      },
      comparable_set: {
        id: '01JSET',
      },
    });
  }
}

const catalog: MarketReferenceCatalog = {
  version: 'test',
  continents: [],
  currencies: [
    {
      code: 'EUR',
      numeric_code: '978',
      name: 'Euro',
      symbol: 'EUR',
      minor_unit: 2,
      cash_minor_unit: 2,
    },
  ],
};

const crossMarketItem: ComparableSetItem = {
  id: '01JITEM',
  comparable_record_id: '01JCOMPARABLE',
  decision: 'excluded',
  rank: null,
  score_basis_points: 4160,
  factor_scores: {
    exact_model: 3000,
    exact_variant: 1000,
    geographic_relevance: 0,
    source_reliability: 160,
  },
  reason_codes: ['cross_country_normalization_unavailable'],
  evidence: {
    id: '01JCOMPARABLE',
    source_key: 'manual',
    source_name: 'Manual entry',
    source_identity_hash: 'a'.repeat(64),
    evidence_hash: 'b'.repeat(64),
    marketplace_name: 'Verified US Market',
    source_url: 'https://market.example/listing/1',
    external_id: 'listing-1',
    title: 'Comparable tool',
    listing_type: 'product',
    condition: 'used_good',
    seller_type: 'private',
    asking_price_minor: 20000,
    currency_code: 'USD',
    country_code: 'US',
    included_accessories: ['case'],
    missing_accessories: [],
    product_variant_id: null,
    product_variant: null,
    source_reliability_basis_points: 4000,
    published_at: null,
    observed_at: '2026-07-26T10:00:00Z',
    market_normalization: null,
  },
};

describe('ComparableMarketNormalizationComponent', () => {
  it('records exact cross-market evidence in target-currency minor units', () => {
    const service = new AnalysisServiceStub();
    TestBed.configureTestingModule({
      imports: [ComparableMarketNormalizationComponent],
      providers: [{ provide: AnalysisService, useValue: service }],
    });
    const fixture = TestBed.createComponent(
      ComparableMarketNormalizationComponent,
    );
    fixture.componentRef.setInput('analysisId', '01JANALYSIS');
    fixture.componentRef.setInput('item', crossMarketItem);
    fixture.componentRef.setInput('targetCountryCode', 'DE');
    fixture.componentRef.setInput('targetCurrencyCode', 'EUR');
    fixture.componentRef.setInput('catalog', catalog);
    fixture.componentRef.setInput('canManage', true);
    let saved = 0;
    fixture.componentInstance.saved.subscribe(() => {
      saved += 1;
    });
    fixture.detectChanges();

    const recordButton = fixture.nativeElement.querySelector(
      'button.button-quiet',
    ) as HTMLButtonElement;
    recordButton.click();
    fixture.detectChanges();

    setInput(fixture.nativeElement, 'market_factor_percent', '110.00');
    setInput(fixture.nativeElement, 'shipping', '10.00');
    setInput(fixture.nativeElement, 'import_duty', '5.00');
    setInput(fixture.nativeElement, 'tax', '0');
    setInput(fixture.nativeElement, 'other_cost', '');
    setInput(
      fixture.nativeElement,
      'evidence_reference',
      'https://evidence.example/us-to-de',
    );
    setInput(
      fixture.nativeElement,
      'compatibility_note',
      'Verified equivalent market segment and landed costs.',
    );
    const confirmation = fixture.nativeElement.querySelector(
      '[formcontrolname="evidence_confirmed"]',
    ) as HTMLInputElement;
    confirmation.click();
    fixture.nativeElement
      .querySelector('form')
      .dispatchEvent(new Event('submit'));
    fixture.detectChanges();

    expect(service.submissions).toHaveLength(1);
    expect(service.submissions[0]).toEqual({
      analysisId: '01JANALYSIS',
      comparableRecordId: '01JCOMPARABLE',
      input: {
        compatibility_status: 'compatible',
        market_factor_basis_points: 11000,
        shipping_minor: 1000,
        import_duty_minor: 500,
        tax_minor: 0,
        other_cost_minor: 0,
        evidence_reference: 'https://evidence.example/us-to-de',
        compatibility_note:
          'Verified equivalent market segment and landed costs.',
        observed_at: expect.any(String),
        evidence_confirmed: true,
      },
    });
    expect(saved).toBe(1);
    expect(fixture.nativeElement.textContent).toContain(
      'Immutable market-normalization evidence was recorded',
    );
  });

  it('renders immutable formula and rate provenance from an existing snapshot', () => {
    TestBed.configureTestingModule({
      imports: [ComparableMarketNormalizationComponent],
      providers: [
        { provide: AnalysisService, useValue: new AnalysisServiceStub() },
      ],
    });
    const fixture = TestBed.createComponent(
      ComparableMarketNormalizationComponent,
    );
    fixture.componentRef.setInput('analysisId', '01JANALYSIS');
    fixture.componentRef.setInput('item', {
      ...crossMarketItem,
      decision: 'included',
      evidence: {
        ...crossMarketItem.evidence,
        market_normalization: {
          id: '01JNORMALIZATION',
          evidence_hash: 'c'.repeat(64),
          calculation_version: 'comparable-market-normalization:v1',
          compatibility_status: 'compatible',
          source_country_code: 'US',
          target_country_code: 'DE',
          source_currency_code: 'USD',
          target_currency_code: 'EUR',
          source_amount_minor: 20000,
          converted_amount_minor: 18000,
          market_factor_basis_points: 11000,
          market_adjusted_amount_minor: 19800,
          shipping_minor: 1000,
          import_duty_minor: 500,
          tax_minor: 0,
          other_cost_minor: 0,
          normalized_amount_minor: 21300,
          exchange_rate_id: '01JRATE',
          rate_direction: 'direct',
          rate_value: '0.9000000000',
          rate_effective_at: '2026-07-26T09:00:00Z',
          rate_provider: 'manual-approved',
          rate_provider_reference: 'fx-2026-07-26',
          evidence_reference: 'https://evidence.example/us-to-de',
          compatibility_note: 'Verified equivalent market segment.',
          reason_codes: [
            'regional_compatibility_confirmed',
            'dated_currency_normalized',
          ],
          observed_at: '2026-07-26T10:00:00Z',
          created_at: '2026-07-26T10:01:00Z',
        },
      },
    } satisfies ComparableSetItem);
    fixture.componentRef.setInput('targetCountryCode', 'DE');
    fixture.componentRef.setInput('targetCurrencyCode', 'EUR');
    fixture.componentRef.setInput('catalog', catalog);
    fixture.componentRef.setInput('canManage', false);
    fixture.detectChanges();

    const text = fixture.nativeElement.textContent as string;

    expect(text).toContain('$200.00');
    expect(text).toContain('€213.00');
    expect(text).toContain('€10.00');
    expect(text).toContain('€5.00');
    expect(text).toContain('0.9000000000');
    expect(text).toContain('Direct rate');
    expect(text).toContain('manual-approved');
    expect(text).toContain('fx-2026-07-26');
    expect(text).toContain('comparable-market-normalization:v1');
    expect(text).toContain('c'.repeat(64));
    expect(text).toContain('Regional compatibility confirmed');
    expect(text).toContain('https://evidence.example/us-to-de');
  });
});

function setInput(
  element: HTMLElement,
  controlName: string,
  value: string,
): void {
  const input = element.querySelector(
    `[formcontrolname="${controlName}"]`,
  ) as HTMLInputElement;
  input.value = value;
  input.dispatchEvent(new Event('input'));
}
