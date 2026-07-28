import { Component, inject, input } from '@angular/core';

import {
  DealScore,
  DealScoreItem,
} from '../../../../core/analysis.models';
import { AnalysisCodeLabelService } from '../../../../core/i18n/analysis-code-label.service';
import { I18nService } from '../../../../core/i18n/i18n.service';
import { TranslatePipe } from '../../../../core/i18n/translate.pipe';

@Component({
  selector: 'app-deal-score-panel',
  imports: [TranslatePipe],
  templateUrl: './deal-score-panel.component.html',
  styleUrl: './deal-score-panel.component.scss',
})
export class DealScorePanelComponent {
  private readonly i18n = inject(I18nService);
  private readonly codeLabels = inject(AnalysisCodeLabelService);

  readonly record = input.required<DealScore>();

  protected label(value: string): string {
    return this.codeLabels.label(value);
  }

  protected percent(basisPoints: number | null): string {
    return basisPoints === null
      ? this.i18n.translate('common.unknown')
      : this.i18n.formatNumber(basisPoints / 10_000, {
          style: 'percent',
          minimumFractionDigits: 2,
          maximumFractionDigits: 2,
        });
  }

  protected points(basisPoints: number | null): string {
    return basisPoints === null
      ? this.i18n.translate('common.unknown')
      : this.i18n.translate('dealScore.points', {
          value: this.i18n.formatNumber(basisPoints / 100, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
          }),
        });
  }

  protected raw(item: DealScoreItem): string {
    if (item.raw_value === null) {
      return this.i18n.translate('common.unknown');
    }

    if (item.raw_value_unit === 'basis_points') {
      return this.i18n.formatNumber(item.raw_value / 10_000, {
        style: 'percent',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
      });
    }

    if (item.raw_value_unit === 'risk_points') {
      return this.i18n.translate('dealScore.risk', {
        value: this.i18n.formatNumber(item.raw_value),
      });
    }

    return `${this.i18n.formatNumber(item.raw_value)} / 100`;
  }

  protected score(value: number | null): string {
    return value === null
      ? this.i18n.translate('dealScore.insufficient')
      : `${this.i18n.formatNumber(value)} / 100`;
  }

  protected verificationAction(value: string): string {
    return this.codeLabels.verificationAction(value);
  }
}
