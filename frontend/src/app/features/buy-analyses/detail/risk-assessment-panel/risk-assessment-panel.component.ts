import { Component, inject, input } from '@angular/core';

import {
  RiskAssessment,
  RiskSignal,
} from '../../../../core/analysis.models';
import { AnalysisCodeLabelService } from '../../../../core/i18n/analysis-code-label.service';
import { I18nService } from '../../../../core/i18n/i18n.service';
import { TranslatePipe } from '../../../../core/i18n/translate.pipe';

@Component({
  selector: 'app-risk-assessment-panel',
  imports: [TranslatePipe],
  templateUrl: './risk-assessment-panel.component.html',
  styleUrl: './risk-assessment-panel.component.scss',
})
export class RiskAssessmentPanelComponent {
  private readonly i18n = inject(I18nService);
  private readonly codeLabels = inject(AnalysisCodeLabelService);

  readonly record = input.required<RiskAssessment>();

  protected label(value: string): string {
    return this.codeLabels.label(value);
  }

  protected percentage(value: number): string {
    return this.i18n.formatNumber(value / 10_000, {
      style: 'percent',
      minimumFractionDigits: 1,
      maximumFractionDigits: 1,
    });
  }

  protected date(value: string): string {
    return this.i18n.formatDate(value, {
      dateStyle: 'medium',
      timeStyle: 'short',
    });
  }

  protected componentEntries(
    components: Readonly<Record<string, number>>,
  ): readonly Readonly<{ key: string; value: number }>[] {
    return Object.entries(components)
      .map(([key, value]) => ({ key, value }))
      .filter((component) => component.value > 0);
  }

  protected evidenceEntries(
    signal: RiskSignal,
  ): readonly Readonly<{ key: string; value: string }>[] {
    return Object.entries(signal.evidence).map(([key, value]) => ({
      key,
      value: this.evidenceValue(value),
    }));
  }

  private evidenceValue(value: unknown): string {
    if (value === null || value === undefined || value === '') {
      return this.i18n.translate('common.notRecorded');
    }

    if (Array.isArray(value)) {
      return value.length === 0
        ? this.i18n.translate('common.noneRecorded')
        : value.join(', ');
    }

    if (typeof value === 'object') {
      return JSON.stringify(value);
    }

    return String(value);
  }

  protected verificationAction(value: string): string {
    return this.codeLabels.verificationAction(value);
  }
}
