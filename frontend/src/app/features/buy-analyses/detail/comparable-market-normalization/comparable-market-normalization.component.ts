import { Component, computed, inject, input, output, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { finalize } from 'rxjs';

import { apiErrorMessage } from '../../../../core/api/api-error';
import {
  ComparableMarketNormalizationInput,
  ComparableSetItem,
  MarketCompatibilityStatus,
} from '../../../../core/analysis.models';
import { AnalysisService } from '../../../../core/analysis.service';
import { AnalysisCodeLabelService } from '../../../../core/i18n/analysis-code-label.service';
import { I18nService } from '../../../../core/i18n/i18n.service';
import { TranslatePipe } from '../../../../core/i18n/translate.pipe';
import { parseMoneyToMinor } from '../../../../core/listing-money';
import { MarketReferenceCatalog } from '../../../../core/market.models';

@Component({
  selector: 'app-comparable-market-normalization',
  imports: [ReactiveFormsModule, TranslatePipe],
  templateUrl: './comparable-market-normalization.component.html',
  styleUrl: './comparable-market-normalization.component.scss',
})
export class ComparableMarketNormalizationComponent {
  private readonly analyses = inject(AnalysisService);
  private readonly formBuilder = inject(FormBuilder);
  private readonly i18n = inject(I18nService);
  private readonly codeLabels = inject(AnalysisCodeLabelService);

  readonly analysisId = input.required<string>();
  readonly item = input.required<ComparableSetItem>();
  readonly targetCountryCode = input.required<string>();
  readonly targetCurrencyCode = input.required<string | null>();
  readonly catalog = input.required<MarketReferenceCatalog | null>();
  readonly canManage = input(false);
  readonly saved = output<void>();

  protected readonly open = signal(false);
  protected readonly saving = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly success = signal<string | null>(null);
  protected readonly normalization = computed(
    () => this.item().evidence.market_normalization ?? null,
  );
  protected readonly requiresNormalization = computed(
    () =>
      this.item().evidence.country_code !== this.targetCountryCode() ||
      this.item().evidence.currency_code !== this.targetCurrencyCode(),
  );
  protected readonly form = this.formBuilder.nonNullable.group({
    compatibility_status: [
      'compatible' as MarketCompatibilityStatus,
      [Validators.required],
    ],
    market_factor_percent: [
      '100.00',
      [Validators.required, Validators.maxLength(7)],
    ],
    shipping: ['', [Validators.maxLength(32)]],
    import_duty: ['', [Validators.maxLength(32)]],
    tax: ['', [Validators.maxLength(32)]],
    other_cost: ['', [Validators.maxLength(32)]],
    evidence_reference: ['', [Validators.required, Validators.maxLength(2048)]],
    compatibility_note: ['', [Validators.required, Validators.maxLength(5000)]],
    observed_at: ['', [Validators.required]],
    evidence_confirmed: [false, [Validators.requiredTrue]],
  });

  protected showForm(): void {
    if (!this.canManage() || !this.requiresNormalization()) {
      return;
    }

    this.error.set(null);
    this.success.set(null);
    this.form.reset({
      compatibility_status: 'compatible',
      market_factor_percent: '100.00',
      shipping: '',
      import_duty: '',
      tax: '',
      other_cost: '',
      evidence_reference: '',
      compatibility_note: '',
      observed_at: this.localDateTime(new Date()),
      evidence_confirmed: false,
    });
    this.open.set(true);
  }

  protected closeForm(): void {
    if (!this.saving()) {
      this.open.set(false);
      this.error.set(null);
    }
  }

  protected submit(): void {
    if (
      !this.canManage() ||
      !this.requiresNormalization() ||
      this.targetCurrencyCode() === null
    ) {
      this.error.set(
        this.i18n.translate(
          'analysisDetail.normalization.targetCurrencyRequired',
        ),
      );
      return;
    }

    if (this.form.invalid) {
      this.form.markAllAsTouched();
      this.error.set(
        this.i18n.translate('analysisDetail.normalization.invalidForm'),
      );
      return;
    }

    const value = this.form.getRawValue();
    const observedAt = this.isoDate(value.observed_at);

    if (observedAt === null) {
      this.error.set(
        this.i18n.translate('analysisDetail.normalization.invalidForm'),
      );
      return;
    }

    const input: ComparableMarketNormalizationInput = {
      compatibility_status: value.compatibility_status,
      evidence_reference: value.evidence_reference.trim(),
      compatibility_note: value.compatibility_note.trim(),
      observed_at: observedAt,
      evidence_confirmed: true,
    };

    if (value.compatibility_status === 'compatible') {
      const marketFactor = this.marketFactor(value.market_factor_percent);

      if (marketFactor === null) {
        this.error.set(
          this.i18n.translate('analysisDetail.normalization.invalidFactor'),
        );
        return;
      }

      try {
        Object.assign(input, {
          market_factor_basis_points: marketFactor,
          shipping_minor: this.money(value.shipping),
          import_duty_minor: this.money(value.import_duty),
          tax_minor: this.money(value.tax),
          other_cost_minor: this.money(value.other_cost),
        });
      } catch {
        this.error.set(
          this.i18n.translate('analysisDetail.normalization.invalidCost'),
        );
        return;
      }
    }

    this.saving.set(true);
    this.error.set(null);
    this.success.set(null);
    this.analyses
      .createMarketNormalization(
        this.analysisId(),
        this.item().comparable_record_id,
        input,
      )
      .pipe(finalize(() => this.saving.set(false)))
      .subscribe({
        next: (response) => {
          this.open.set(false);
          this.success.set(
            this.i18n.translate(
              response.created
                ? 'analysisDetail.normalization.saved'
                : 'analysisDetail.normalization.replayed',
            ),
          );
          this.saved.emit();
        },
        error: (error: unknown) => {
          this.error.set(
            apiErrorMessage(
              error,
              this.i18n.translate('analysisDetail.normalization.saveError'),
            ),
          );
        },
      });
  }

  protected isCompatible(): boolean {
    return this.form.controls.compatibility_status.value === 'compatible';
  }

  protected moneyValue(value: number | null): string {
    const currencyCode = this.targetCurrencyCode();

    if (value === null || currencyCode === null) {
      return this.i18n.translate('common.unavailable');
    }

    return this.i18n.formatMoney(
      value,
      currencyCode,
      this.targetMinorUnit(),
    );
  }

  protected sourceMoneyValue(value: number, currencyCode: string): string {
    const minorUnit =
      this.catalog()?.currencies.find(
        (currency) => currency.code === currencyCode,
      )?.minor_unit ?? 2;

    return this.i18n.formatMoney(value, currencyCode, minorUnit);
  }

  protected factor(value: number | null): string {
    return value === null
      ? this.i18n.translate('common.unavailable')
      : this.i18n.formatNumber(value / 10_000, {
          style: 'percent',
          minimumFractionDigits: 2,
          maximumFractionDigits: 2,
        });
  }

  protected date(value: string | null): string {
    return value === null
      ? this.i18n.translate('common.unavailable')
      : this.i18n.formatDate(value, {
          dateStyle: 'medium',
          timeStyle: 'short',
        });
  }

  protected code(value: string): string {
    return this.codeLabels.label(value);
  }

  private money(value: string): number {
    if (value.trim() === '') {
      return 0;
    }

    const parsed = parseMoneyToMinor(value, this.targetMinorUnit());

    if (parsed === null || parsed < 0) {
      throw new Error('invalid_market_normalization_cost');
    }

    return parsed;
  }

  private marketFactor(value: string): number | null {
    const normalized = value.trim().replace(',', '.');

    if (!/^\d{1,3}(?:\.\d{1,2})?$/.test(normalized)) {
      return null;
    }

    const basisPoints = Math.round(Number(normalized) * 100);

    return basisPoints >= 5000 && basisPoints <= 15000
      ? basisPoints
      : null;
  }

  private targetMinorUnit(): number {
    return (
      this.catalog()?.currencies.find(
        (currency) => currency.code === this.targetCurrencyCode(),
      )?.minor_unit ?? 2
    );
  }

  private isoDate(value: string): string | null {
    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? null : date.toISOString();
  }

  private localDateTime(date: Date): string {
    const local = new Date(date.getTime() - date.getTimezoneOffset() * 60_000);

    return local.toISOString().slice(0, 16);
  }
}
