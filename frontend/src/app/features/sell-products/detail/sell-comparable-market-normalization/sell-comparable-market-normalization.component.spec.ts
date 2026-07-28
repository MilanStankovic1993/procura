import { TestBed } from '@angular/core/testing';
import { of } from 'rxjs';

import { MarketReferenceCatalog } from '../../../../core/market.models';
import {
  SellComparableMarketNormalizationInput,
  SellComparableSelectionItem,
} from '../../../../core/owned-product.models';
import { OwnedProductService } from '../../../../core/owned-product.service';
import { SellComparableMarketNormalizationComponent } from './sell-comparable-market-normalization.component';

class OwnedProductServiceStub {
  readonly submissions: {
    ownedProductId: string;
    comparableRecordId: string;
    input: SellComparableMarketNormalizationInput;
  }[] = [];

  createSellComparableMarketNormalization(
    ownedProductId: string,
    comparableRecordId: string,
    input: SellComparableMarketNormalizationInput,
  ) {
    this.submissions.push({ ownedProductId, comparableRecordId, input });

    return of({
      data: { id: '01JSELLNORMALIZATION' },
      meta: {
        created: true,
        selection: { id: '01JSELECTION' },
        price_band: { id: '01JBAND' },
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

const crossMarketItem: SellComparableSelectionItem = {
  id: '01JITEM',
  sell_comparable_record_id: '01JSELLCOMPARABLE',
  decision: 'excluded',
  rank: null,
  score_basis_points: 0,
  factor_scores: {},
  reason_codes: ['cross_country_normalization_unavailable'],
  evidence: {
    marketplace_name: 'Verified French Market',
    title: 'Comparable tool',
    asking_price_minor: 20000,
    currency_code: 'USD',
    country_code: 'FR',
    observed_at: '2026-07-26T10:00:00Z',
    market_normalization: null,
  },
};

describe('SellComparableMarketNormalizationComponent', () => {
  it('submits exact target scope, factor and target-currency costs', () => {
    const service = new OwnedProductServiceStub();
    TestBed.configureTestingModule({
      imports: [SellComparableMarketNormalizationComponent],
      providers: [{ provide: OwnedProductService, useValue: service }],
    });
    const fixture = TestBed.createComponent(
      SellComparableMarketNormalizationComponent,
    );
    fixture.componentRef.setInput('ownedProductId', '01JOWNED');
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

    (
      fixture.nativeElement.querySelector(
        'button.button-quiet',
      ) as HTMLButtonElement
    ).click();
    fixture.detectChanges();
    setInput(fixture.nativeElement, 'market_factor_percent', '110.00');
    setInput(fixture.nativeElement, 'shipping', '10.00');
    setInput(fixture.nativeElement, 'import_duty', '5.00');
    setInput(fixture.nativeElement, 'tax', '0');
    setInput(fixture.nativeElement, 'other_cost', '');
    setInput(
      fixture.nativeElement,
      'evidence_reference',
      'sell-ops-ticket-1',
    );
    setInput(
      fixture.nativeElement,
      'compatibility_note',
      'Verified equivalent Sell market segment and landed costs.',
    );
    (
      fixture.nativeElement.querySelector(
        '[formcontrolname="evidence_confirmed"]',
      ) as HTMLInputElement
    ).click();
    fixture.nativeElement
      .querySelector('form')
      .dispatchEvent(new Event('submit'));
    fixture.detectChanges();

    expect(service.submissions).toHaveLength(1);
    expect(service.submissions[0]).toEqual({
      ownedProductId: '01JOWNED',
      comparableRecordId: '01JSELLCOMPARABLE',
      input: {
        target_country_code: 'DE',
        target_currency_code: 'EUR',
        compatibility_status: 'compatible',
        market_factor_basis_points: 11000,
        shipping_minor: 1000,
        import_duty_minor: 500,
        tax_minor: 0,
        other_cost_minor: 0,
        evidence_reference: 'sell-ops-ticket-1',
        compatibility_note:
          'Verified equivalent Sell market segment and landed costs.',
        observed_at: expect.any(String),
        evidence_confirmed: true,
      },
    });
    expect(saved).toBe(1);
  });

  it('renders immutable amount, rate and evidence provenance', () => {
    TestBed.configureTestingModule({
      imports: [SellComparableMarketNormalizationComponent],
      providers: [
        { provide: OwnedProductService, useValue: new OwnedProductServiceStub() },
      ],
    });
    const fixture = TestBed.createComponent(
      SellComparableMarketNormalizationComponent,
    );
    fixture.componentRef.setInput('ownedProductId', '01JOWNED');
    fixture.componentRef.setInput('item', {
      ...crossMarketItem,
      decision: 'included',
      evidence: {
        ...crossMarketItem.evidence,
        market_normalization: {
          id: '01JSELLNORMALIZATION',
          evidence_hash: 'c'.repeat(64),
          calculation_version: 'sell-comparable-market-normalization:v1',
          compatibility_status: 'compatible',
          source_country_code: 'FR',
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
          rate_provider: 'approved-manual',
          rate_provider_reference: 'sell-fx-2026-07-26',
          evidence_reference: 'sell-ops-ticket-1',
          compatibility_note: 'Verified equivalent Sell market segment.',
          reason_codes: ['regional_compatibility_confirmed'],
          observed_at: '2026-07-26T10:00:00Z',
          created_at: '2026-07-26T10:01:00Z',
        },
      },
    } satisfies SellComparableSelectionItem);
    fixture.componentRef.setInput('targetCountryCode', 'DE');
    fixture.componentRef.setInput('targetCurrencyCode', 'EUR');
    fixture.componentRef.setInput('catalog', catalog);
    fixture.componentRef.setInput('canManage', false);
    fixture.detectChanges();
    const text = fixture.nativeElement.textContent as string;

    expect(text).toContain('$200.00');
    expect(text).toContain('€213.00');
    expect(text).toContain('€10.00');
    expect(text).toContain('0.9000000000');
    expect(text).toContain('approved-manual');
    expect(text).toContain('sell-fx-2026-07-26');
    expect(text).toContain('sell-comparable-market-normalization:v1');
    expect(text).toContain('c'.repeat(64));
    expect(text).toContain('sell-ops-ticket-1');
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
