import {
  Component,
  effect,
  inject,
  input,
  output,
  signal,
} from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { finalize } from 'rxjs';

import { apiErrorMessage } from '../../../../core/api/api-error';
import {
  Analysis,
  CostInput,
  CostInputSubmission,
  PriceEstimate,
  ProfitEstimate,
  RiskAssessment,
} from '../../../../core/analysis.models';
import { AnalysisService } from '../../../../core/analysis.service';
import { AnalysisCodeLabelService } from '../../../../core/i18n/analysis-code-label.service';
import { I18nService } from '../../../../core/i18n/i18n.service';
import { TranslatePipe } from '../../../../core/i18n/translate.pipe';
import { parseMoneyToMinor } from '../../../../core/listing-money';
import { MarketReferenceCatalog } from '../../../../core/market.models';

@Component({
  selector: 'app-cost-profit-panel',
  imports: [ReactiveFormsModule, TranslatePipe],
  templateUrl: './cost-profit-panel.component.html',
  styleUrl: './cost-profit-panel.component.scss',
})
export class CostProfitPanelComponent {
  private readonly analyses = inject(AnalysisService);
  private readonly formBuilder = inject(FormBuilder);
  private readonly i18n = inject(I18nService);
  private readonly codeLabels = inject(AnalysisCodeLabelService);
  private hydratedEvidenceKey: string | null = null;

  readonly analysisId = input.required<string>();
  readonly sourceCountryCode = input.required<string>();
  readonly targetCountryCode = input.required<string>();
  readonly listingAskingPriceMinor = input<number | null>(null);
  readonly listingCurrencyCode = input<string | null>(null);
  readonly priceEstimate = input.required<PriceEstimate>();
  readonly riskAssessment = input.required<RiskAssessment>();
  readonly costInput = input<CostInput | null>(null);
  readonly profitEstimate = input<ProfitEstimate | null>(null);
  readonly catalog = input<MarketReferenceCatalog | null>(null);
  readonly canManage = input(false);
  readonly analysisUpdated = output<Analysis>();

  protected readonly saving = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly success = signal<string | null>(null);
  protected readonly form = this.formBuilder.nonNullable.group({
    purchase_price: ['', [Validators.maxLength(32)]],
    transport: ['', [Validators.maxLength(32)]],
    repair: ['', [Validators.maxLength(32)]],
    platform_fees: ['', [Validators.maxLength(32)]],
    payment_fees: ['', [Validators.maxLength(32)]],
    customs: ['', [Validators.maxLength(32)]],
    tax: ['', [Validators.maxLength(32)]],
    other_costs: ['', [Validators.maxLength(32)]],
    safety_reserve: ['', [Validators.maxLength(32)]],
    regional_compatibility_confirmed: [false],
  });

  constructor() {
    effect(() => {
      const priceEstimate = this.priceEstimate();
      const costInput = this.costInput();
      const evidenceKey = `${priceEstimate.id}:${costInput?.id ?? 'new'}`;

      if (evidenceKey === this.hydratedEvidenceKey) {
        return;
      }

      this.hydratedEvidenceKey = evidenceKey;
      this.hydrateForm(costInput, priceEstimate);
    });
  }

  protected submit(): void {
    if (!this.canManage()) {
      return;
    }

    if (this.form.invalid) {
      this.form.markAllAsTouched();
      this.error.set(this.i18n.translate('costProfit.formError'));
      return;
    }

    const values = this.form.getRawValue();
    const minorUnit = this.minorUnit();
    let input: CostInputSubmission;

    try {
      input = {
        price_estimate_id: this.priceEstimate().id,
        risk_assessment_id: this.riskAssessment().id,
        currency_code: this.priceEstimate().target_currency_code,
        purchase_price_minor: this.parseAmount(values.purchase_price, minorUnit),
        transport_minor: this.parseAmount(values.transport, minorUnit),
        repair_minor: this.parseAmount(values.repair, minorUnit),
        platform_fees_minor: this.parseAmount(values.platform_fees, minorUnit),
        payment_fees_minor: this.parseAmount(values.payment_fees, minorUnit),
        customs_minor: this.parseAmount(values.customs, minorUnit),
        tax_minor: this.parseAmount(values.tax, minorUnit),
        other_costs_minor: this.parseAmount(values.other_costs, minorUnit),
        safety_reserve_minor: this.parseAmount(values.safety_reserve, minorUnit),
        regional_compatibility_confirmed: this.crossBorder()
          ? values.regional_compatibility_confirmed
          : null,
      };
    } catch {
      this.error.set(this.i18n.translate('costProfit.amountError'));
      return;
    }

    this.saving.set(true);
    this.error.set(null);
    this.success.set(null);
    this.analyses
      .confirmCosts(this.analysisId(), input)
      .pipe(finalize(() => this.saving.set(false)))
      .subscribe({
        next: (response) => {
          this.success.set(
            response.created
              ? this.i18n.translate('costProfit.created')
              : this.i18n.translate('costProfit.duplicate'),
          );
          this.analysisUpdated.emit(response.analysis);
        },
        error: (error: unknown) => {
          this.error.set(
            apiErrorMessage(
              error,
              this.i18n.translate('costProfit.saveError'),
            ),
          );
        },
      });
  }

  protected crossBorder(): boolean {
    return this.sourceCountryCode() !== this.targetCountryCode();
  }

  protected money(amountMinor: number | null): string {
    if (amountMinor === null) {
      return this.i18n.translate('common.unknown');
    }

    return this.i18n.formatMoney(
      amountMinor,
      this.priceEstimate().target_currency_code,
      this.minorUnit(),
    );
  }

  protected percentage(value: number | null): string {
    return value === null
      ? this.i18n.translate('common.notCalculated')
      : this.i18n.formatNumber(value / 10_000, {
          style: 'percent',
          minimumFractionDigits: 2,
          maximumFractionDigits: 2,
        });
  }

  protected confidence(value: number): string {
    return this.i18n.formatNumber(value / 10_000, {
      style: 'percent',
      minimumFractionDigits: 1,
      maximumFractionDigits: 1,
    });
  }

  protected label(value: string): string {
    return this.codeLabels.label(value);
  }

  protected confidenceEntries(
    values: Readonly<Record<string, number>>,
  ): readonly Readonly<{ key: string; value: number }>[] {
    return Object.entries(values).map(([key, value]) => ({ key, value }));
  }

  private hydrateForm(
    costInput: CostInput | null,
    priceEstimate: PriceEstimate,
  ): void {
    const itemAmount = (category: string): string => {
      const amount = costInput?.items.find(
        (item) => item.category === category,
      )?.amount_minor;

      return amount === null || amount === undefined
        ? ''
        : this.minorToInput(amount, this.minorUnit());
    };
    const listingDefault =
      costInput === null &&
      this.listingCurrencyCode() === priceEstimate.target_currency_code
        ? this.listingAskingPriceMinor()
        : null;

    this.form.reset({
      purchase_price:
        costInput === null && listingDefault !== null
          ? this.minorToInput(listingDefault, this.minorUnit())
          : itemAmount('purchase_price'),
      transport: itemAmount('transport'),
      repair: itemAmount('repair'),
      platform_fees: itemAmount('platform_fees'),
      payment_fees: itemAmount('payment_fees'),
      customs: itemAmount('customs'),
      tax: itemAmount('tax'),
      other_costs: itemAmount('other_costs'),
      safety_reserve: itemAmount('safety_reserve'),
      regional_compatibility_confirmed:
        costInput?.regional_compatibility_confirmed ?? false,
    });
  }

  private parseAmount(value: string, minorUnit: number): number | null {
    return parseMoneyToMinor(value, minorUnit);
  }

  private minorUnit(): number {
    const currencyCode = this.priceEstimate().target_currency_code;

    return (
      this.catalog()?.currencies.find(
        (currency) => currency.code === currencyCode,
      )?.minor_unit ?? 2
    );
  }

  private minorToInput(amountMinor: number, minorUnit: number): string {
    if (minorUnit === 0) {
      return String(amountMinor);
    }

    const divisor = 10 ** minorUnit;
    const whole = Math.floor(amountMinor / divisor);
    const fraction = String(amountMinor % divisor).padStart(minorUnit, '0');

    return `${whole}.${fraction}`;
  }
}
