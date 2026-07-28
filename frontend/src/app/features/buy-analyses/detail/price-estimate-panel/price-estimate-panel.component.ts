import { Component, inject, input } from '@angular/core';

import {
  PriceEstimate,
  PriceEstimateItem,
} from '../../../../core/analysis.models';
import { AnalysisCodeLabelService } from '../../../../core/i18n/analysis-code-label.service';
import { I18nService } from '../../../../core/i18n/i18n.service';
import { TranslatePipe } from '../../../../core/i18n/translate.pipe';
import { MarketReferenceCatalog } from '../../../../core/market.models';

@Component({
  selector: 'app-price-estimate-panel',
  imports: [TranslatePipe],
  templateUrl: './price-estimate-panel.component.html',
  styleUrl: './price-estimate-panel.component.scss',
})
export class PriceEstimatePanelComponent {
  private readonly i18n = inject(I18nService);
  private readonly codeLabels = inject(AnalysisCodeLabelService);

  readonly record = input.required<PriceEstimate>();
  readonly catalog = input<MarketReferenceCatalog | null>(null);

  protected money(
    amountMinor: number | null,
    currencyCode: string,
  ): string {
    if (amountMinor === null) {
      return this.i18n.translate('common.notCalculated');
    }

    const minorUnit =
      this.catalog()?.currencies.find(
        (currency) => currency.code === currencyCode,
      )?.minor_unit ?? 2;

    return this.i18n.formatMoney(amountMinor, currencyCode, minorUnit);
  }

  protected itemMoney(
    item: PriceEstimateItem,
    target = false,
  ): string {
    return this.money(
      target ? item.target_amount_minor : item.original_amount_minor,
      target ? item.target_currency_code : item.original_currency_code,
    );
  }

  protected factorEntries(
    factors: Readonly<Record<string, number>>,
  ): readonly Readonly<{ key: string; value: number }>[] {
    return Object.entries(factors)
      .map(([key, value]) => ({ key, value }))
      .filter((factor) => factor.value > 0);
  }

  protected label(value: string): string {
    return this.codeLabels.label(value);
  }

  protected date(value: string | null): string {
    if (value === null) {
      return this.i18n.translate('common.notYet');
    }

    return this.i18n.formatDate(value, {
      dateStyle: 'medium',
      timeStyle: 'short',
    });
  }

  protected percentage(value: number | null): string {
    return value === null
      ? this.i18n.translate('common.pending')
      : this.i18n.formatNumber(value / 10_000, {
          style: 'percent',
          minimumFractionDigits: 1,
          maximumFractionDigits: 1,
        });
  }
}
