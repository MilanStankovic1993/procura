import { Component, computed, inject, input, output, signal } from '@angular/core';
import { finalize } from 'rxjs';

import { apiErrorMessage } from '../../../core/api/api-error';
import { AnalysisCodeLabelService } from '../../../core/i18n/analysis-code-label.service';
import { I18nService } from '../../../core/i18n/i18n.service';
import { TranslatePipe } from '../../../core/i18n/translate.pipe';
import {
  OwnedProduct,
  OwnedProductAssessment,
} from '../../../core/owned-product.models';
import { OwnedProductService } from '../../../core/owned-product.service';

@Component({
  selector: 'app-owned-product-assessment-panel',
  imports: [TranslatePipe],
  templateUrl: './owned-product-assessment-panel.component.html',
  styleUrl: './owned-product-assessment-panel.component.scss',
})
export class OwnedProductAssessmentPanelComponent {
  private readonly ownedProducts = inject(OwnedProductService);
  private readonly i18n = inject(I18nService);
  private readonly codeLabels = inject(AnalysisCodeLabelService);

  readonly record = input.required<OwnedProduct>();
  readonly canManage = input(false);
  readonly assessmentRecorded = output<void>();

  protected readonly assessing = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly success = signal(false);
  protected readonly assessment = computed(
    () =>
      this.record().current_assessment ??
      this.record().assessments?.[0] ??
      null,
  );
  protected readonly stale = computed(
    () =>
      (this.record().assessments?.length ?? 0) > 0 &&
      this.record().current_assessment === null,
  );
  protected readonly canAssess = computed(
    () =>
      this.canManage() &&
      this.record().status === 'ready' &&
      (this.record().snapshots?.length ?? 0) > 0,
  );

  protected assess(): void {
    const product = this.record();
    const snapshot = product.snapshots?.[0];

    if (!this.canAssess() || snapshot === undefined || this.assessing()) {
      return;
    }

    this.assessing.set(true);
    this.error.set(null);
    this.success.set(false);
    this.ownedProducts
      .assess(product.id, snapshot.id)
      .pipe(finalize(() => this.assessing.set(false)))
      .subscribe({
        next: () => {
          this.success.set(true);
          this.assessmentRecorded.emit();
        },
        error: (error: unknown) => {
          this.error.set(
            apiErrorMessage(
              error,
              this.i18n.translate('ownedProduct.assessment.error'),
            ),
          );
        },
      });
  }

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

  protected date(value: string | null): string {
    return value === null
      ? this.i18n.translate('common.unknownDate')
      : this.i18n.formatDate(value, {
          dateStyle: 'medium',
          timeStyle: 'short',
        });
  }

  protected list(value: readonly string[] | null): string {
    if (value === null) {
      return this.i18n.translate('common.unknown');
    }

    return value.length === 0
      ? this.i18n.translate('ownedProduct.common.noneConfirmed')
      : value.join(', ');
  }

  protected isHistorical(assessment: OwnedProductAssessment): boolean {
    return this.record().current_assessment?.id !== assessment.id;
  }
}
