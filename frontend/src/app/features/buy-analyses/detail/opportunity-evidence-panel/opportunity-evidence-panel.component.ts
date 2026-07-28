import { Component, effect, inject, input, output, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { finalize } from 'rxjs';

import { apiErrorMessage } from '../../../../core/api/api-error';
import {
  Analysis,
  ComparableSet,
  CostInput,
  OpportunityAssessment,
  OpportunityEvidenceSubmission,
  OpportunityInput,
  PriceEstimate,
  ProfitEstimate,
  RiskAssessment,
  ShippingMethod,
} from '../../../../core/analysis.models';
import { AnalysisService } from '../../../../core/analysis.service';
import { AnalysisCodeLabelService } from '../../../../core/i18n/analysis-code-label.service';
import { I18nService } from '../../../../core/i18n/i18n.service';
import { TranslatePipe } from '../../../../core/i18n/translate.pipe';

@Component({
  selector: 'app-opportunity-evidence-panel',
  imports: [ReactiveFormsModule, TranslatePipe],
  templateUrl: './opportunity-evidence-panel.component.html',
  styleUrl: './opportunity-evidence-panel.component.scss',
})
export class OpportunityEvidencePanelComponent {
  private readonly analyses = inject(AnalysisService);
  private readonly formBuilder = inject(FormBuilder);
  private readonly i18n = inject(I18nService);
  private readonly codeLabels = inject(AnalysisCodeLabelService);
  private hydratedEvidenceKey: string | null = null;

  readonly analysisId = input.required<string>();
  readonly sourceCountryCode = input.required<string>();
  readonly targetCountryCode = input.required<string>();
  readonly comparableSet = input.required<ComparableSet>();
  readonly priceEstimate = input.required<PriceEstimate>();
  readonly riskAssessment = input.required<RiskAssessment>();
  readonly costInput = input.required<CostInput>();
  readonly profitEstimate = input.required<ProfitEstimate>();
  readonly opportunityInput = input<OpportunityInput | null>(null);
  readonly logisticsAssessment = input<OpportunityAssessment | null>(null);
  readonly demandAssessment = input<OpportunityAssessment | null>(null);
  readonly canManage = input(false);
  readonly analysisUpdated = output<Analysis>();

  protected readonly saving = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly success = signal<string | null>(null);
  protected readonly form = this.formBuilder.nonNullable.group({
    shipping_method: [''],
    shipping_distance_km: ['', [Validators.maxLength(8)]],
    pickup_available: [''],
    tracking_available: [''],
    insurance_available: [''],
    packaging_confirmed: [''],
    cross_border_handling_confirmed: [''],
    sold_comparables_count: ['', [Validators.maxLength(8)]],
    median_days_to_sale: ['', [Validators.maxLength(8)]],
    observation_window_days: ['', [Validators.maxLength(8)]],
    demand_evidence_observed_at: [''],
    demand_evidence_source: ['', [Validators.maxLength(500)]],
  });

  constructor() {
    effect(() => {
      const profitEstimate = this.profitEstimate();
      const opportunityInput = this.opportunityInput();
      const evidenceKey = `${profitEstimate.id}:${opportunityInput?.id ?? 'new'}`;

      if (evidenceKey === this.hydratedEvidenceKey) {
        return;
      }

      this.hydratedEvidenceKey = evidenceKey;
      this.hydrateForm(opportunityInput);
    });
  }

  protected submit(): void {
    if (!this.canManage()) {
      return;
    }

    if (this.form.invalid) {
      this.form.markAllAsTouched();
      this.error.set(this.i18n.translate('opportunity.formError'));
      return;
    }

    const values = this.form.getRawValue();
    let submission: OpportunityEvidenceSubmission;

    try {
      const soldCount = this.parseInteger(
        values.sold_comparables_count,
        this.i18n.translate('opportunity.soldCount'),
      );
      const medianDays =
        soldCount === 0
          ? null
          : this.parseInteger(
              values.median_days_to_sale,
              this.i18n.translate('opportunity.medianDays'),
            );

      if (soldCount !== null && soldCount > 0 && medianDays === null) {
        throw new Error(
          this.i18n.translate('opportunity.medianRequired'),
        );
      }

      submission = {
        comparable_set_id: this.comparableSet().id,
        price_estimate_id: this.priceEstimate().id,
        risk_assessment_id: this.riskAssessment().id,
        cost_input_id: this.costInput().id,
        profit_estimate_id: this.profitEstimate().id,
        shipping_method: this.shippingMethod(values.shipping_method),
        shipping_distance_km: this.parseInteger(
          values.shipping_distance_km,
          this.i18n.translate('opportunity.distance'),
        ),
        pickup_available: this.parseBoolean(values.pickup_available),
        tracking_available: this.parseBoolean(values.tracking_available),
        insurance_available: this.parseBoolean(values.insurance_available),
        packaging_confirmed: this.parseBoolean(values.packaging_confirmed),
        cross_border_handling_confirmed: this.crossBorder()
          ? this.parseBoolean(values.cross_border_handling_confirmed)
          : null,
        sold_comparables_count: soldCount,
        median_days_to_sale: medianDays,
        observation_window_days: this.parseInteger(
          values.observation_window_days,
          this.i18n.translate('opportunity.window'),
        ),
        demand_evidence_observed_at: values.demand_evidence_observed_at
          ? new Date(values.demand_evidence_observed_at).toISOString()
          : null,
        demand_evidence_source: values.demand_evidence_source.trim() || null,
      };
    } catch (error: unknown) {
      this.error.set(
        error instanceof Error
          ? error.message
          : this.i18n.translate('opportunity.formError'),
      );
      return;
    }

    this.saving.set(true);
    this.error.set(null);
    this.success.set(null);
    this.analyses
      .confirmOpportunityEvidence(this.analysisId(), submission)
      .pipe(finalize(() => this.saving.set(false)))
      .subscribe({
        next: (response) => {
          this.success.set(
            response.created
              ? this.i18n.translate('opportunity.created')
              : this.i18n.translate('opportunity.duplicate'),
          );
          this.analysisUpdated.emit(response.analysis);
        },
        error: (error: unknown) => {
          this.error.set(
            apiErrorMessage(
              error,
              this.i18n.translate('opportunity.saveError'),
            ),
          );
        },
      });
  }

  protected crossBorder(): boolean {
    return this.sourceCountryCode() !== this.targetCountryCode();
  }

  protected label(value: string): string {
    return this.codeLabels.label(value);
  }

  protected score(value: number | null): string {
    return value === null
      ? this.i18n.translate('common.unknown')
      : `${this.i18n.formatNumber(value)} / 100`;
  }

  protected confidence(value: number): string {
    return this.i18n.formatNumber(value / 10_000, {
      style: 'percent',
      minimumFractionDigits: 1,
      maximumFractionDigits: 1,
    });
  }

  protected confidenceEntries(
    values: Readonly<Record<string, number>>,
  ): readonly Readonly<{ key: string; value: number }>[] {
    return Object.entries(values).map(([key, value]) => ({ key, value }));
  }

  private hydrateForm(opportunityInput: OpportunityInput | null): void {
    const value = (code: string): string | number | boolean | null =>
      opportunityInput?.items.find((item) => item.code === code)?.value ?? null;
    const booleanValue = (code: string): string => {
      const current = value(code);

      return typeof current === 'boolean' ? String(current) : '';
    };
    const textValue = (code: string): string => {
      const current = value(code);

      return typeof current === 'string' || typeof current === 'number'
        ? String(current)
        : '';
    };

    this.form.reset({
      shipping_method: textValue('shipping_method'),
      shipping_distance_km: textValue('shipping_distance_km'),
      pickup_available: booleanValue('pickup_available'),
      tracking_available: booleanValue('tracking_available'),
      insurance_available: booleanValue('insurance_available'),
      packaging_confirmed: booleanValue('packaging_confirmed'),
      cross_border_handling_confirmed: booleanValue(
        'cross_border_handling_confirmed',
      ),
      sold_comparables_count: textValue('sold_comparables_count'),
      median_days_to_sale: textValue('median_days_to_sale'),
      observation_window_days: textValue('observation_window_days'),
      demand_evidence_observed_at: this.localDateTime(
        textValue('demand_evidence_observed_at'),
      ),
      demand_evidence_source: textValue('demand_evidence_source'),
    });
  }

  private parseInteger(value: string, label: string): number | null {
    const normalized = value.trim();

    if (normalized === '') {
      return null;
    }

    if (!/^\d+$/.test(normalized)) {
      throw new Error(
        this.i18n.translate('opportunity.integerError', { field: label }),
      );
    }

    const parsed = Number(normalized);

    if (!Number.isSafeInteger(parsed)) {
      throw new Error(
        this.i18n.translate('opportunity.rangeError', { field: label }),
      );
    }

    return parsed;
  }

  private parseBoolean(value: string): boolean | null {
    return value === 'true' ? true : value === 'false' ? false : null;
  }

  private shippingMethod(value: string): ShippingMethod | null {
    return value === 'local_pickup' ||
      value === 'parcel' ||
      value === 'seller_arranged' ||
      value === 'freight'
      ? value
      : null;
  }

  private localDateTime(value: string): string {
    if (value === '') {
      return '';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
      return '';
    }

    const offset = date.getTimezoneOffset() * 60_000;

    return new Date(date.getTime() - offset).toISOString().slice(0, 16);
  }
}
