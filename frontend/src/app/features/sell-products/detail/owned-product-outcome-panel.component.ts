import { Component, computed, effect, inject, input, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { finalize } from 'rxjs';

import { apiErrorMessage } from '../../../core/api/api-error';
import { I18nService } from '../../../core/i18n/i18n.service';
import { TranslationKey } from '../../../core/i18n/locales/en';
import { TranslatePipe } from '../../../core/i18n/translate.pipe';
import { parseMoneyToMinor } from '../../../core/listing-money';
import { MarketReferenceCatalog } from '../../../core/market.models';
import { MarketReferenceService } from '../../../core/market-reference.service';
import {
  ActualCostCategory,
  ActualCostItemInput,
  ActualSaleOutcomeType,
  EstimateAccuracyCandidate,
  EstimateAccuracyMetric,
  OutcomeEvidenceKind,
  OutcomeTrackingProjection,
  OwnedProduct,
  SalePortfolioEntry,
} from '../../../core/owned-product.models';
import { OwnedProductService } from '../../../core/owned-product.service';

const COST_CATEGORIES: readonly ActualCostCategory[] = [
  'transport',
  'repair',
  'platform_fees',
  'payment_fees',
  'customs',
  'tax',
  'marketing',
  'other_costs',
];

const EVIDENCE_KINDS: readonly OutcomeEvidenceKind[] = [
  'receipt',
  'invoice',
  'bank_statement',
  'marketplace_record',
  'manual_confirmation',
  'other',
];

@Component({
  selector: 'app-owned-product-outcome-panel',
  imports: [ReactiveFormsModule, TranslatePipe],
  templateUrl: './owned-product-outcome-panel.component.html',
  styleUrl: './owned-product-outcome-panel.component.scss',
})
export class OwnedProductOutcomePanelComponent {
  private readonly ownedProducts = inject(OwnedProductService);
  private readonly markets = inject(MarketReferenceService);
  private readonly formBuilder = inject(FormBuilder);
  private readonly i18n = inject(I18nService);
  private loadedProductId: string | null = null;
  private purchaseIdempotencyKey: string | null = null;
  private costIdempotencyKey: string | null = null;
  private saleIdempotencyKey: string | null = null;
  private accuracyIdempotencyKey: string | null = null;

  readonly record = input.required<OwnedProduct>();
  readonly canManage = input(false);

  protected readonly projection = signal<OutcomeTrackingProjection | null>(null);
  protected readonly marketCatalog = signal<MarketReferenceCatalog | null>(null);
  protected readonly loading = signal(true);
  protected readonly savingPurchase = signal(false);
  protected readonly savingCosts = signal(false);
  protected readonly savingSale = signal(false);
  protected readonly savingAccuracy = signal(false);
  protected readonly loadingCandidates = signal(false);
  protected readonly accuracyCandidates = signal<readonly EstimateAccuracyCandidate[]>([]);
  protected readonly error = signal<string | null>(null);
  protected readonly formError = signal<string | null>(null);
  protected readonly success = signal<string | null>(null);
  protected readonly costCategories = COST_CATEGORIES;
  protected readonly evidenceKinds = EVIDENCE_KINDS;
  protected readonly purchaseForm = this.formBuilder.nonNullable.group({
    amount: ['', [Validators.required, Validators.maxLength(32)]],
    currency_code: ['', [Validators.required]],
    reporting_currency_code: ['', [Validators.required]],
    occurred_at: ['', [Validators.required]],
    evidence_kind: ['' as OutcomeEvidenceKind | '', [Validators.required]],
    evidence_reference: ['', [Validators.maxLength(255)]],
    correction_reason: ['', [Validators.maxLength(64)]],
    note: ['', [Validators.maxLength(1000)]],
  });
  protected readonly costForm = this.formBuilder.nonNullable.group({
    reporting_currency_code: ['', [Validators.required]],
    source_currency_code: [''],
    occurred_at: [''],
    evidence_kind: ['' as OutcomeEvidenceKind | ''],
    evidence_reference: ['', [Validators.maxLength(255)]],
    transport: ['', [Validators.maxLength(32)]],
    repair: ['', [Validators.maxLength(32)]],
    platform_fees: ['', [Validators.maxLength(32)]],
    payment_fees: ['', [Validators.maxLength(32)]],
    customs: ['', [Validators.maxLength(32)]],
    tax: ['', [Validators.maxLength(32)]],
    marketing: ['', [Validators.maxLength(32)]],
    other_costs: ['', [Validators.maxLength(32)]],
    correction_reason: ['', [Validators.maxLength(64)]],
    note: ['', [Validators.maxLength(1000)]],
  });
  protected readonly saleForm = this.formBuilder.nonNullable.group({
    entry_id: ['', [Validators.required]],
    outcome_type: ['' as ActualSaleOutcomeType | '', [Validators.required]],
    amount: ['', [Validators.maxLength(32)]],
    currency_code: [''],
    reporting_currency_code: [''],
    occurred_at: ['', [Validators.required]],
    evidence_kind: ['' as OutcomeEvidenceKind | '', [Validators.required]],
    evidence_reference: ['', [Validators.maxLength(255)]],
    reason_code: ['', [Validators.maxLength(64)]],
    correction_reason: ['', [Validators.maxLength(64)]],
    note: ['', [Validators.maxLength(1000)]],
  });
  protected readonly accuracyForm = this.formBuilder.nonNullable.group({
    analysis_id: ['', [Validators.required]],
    reason_code: [
      'original_buy_estimate_confirmed',
      [Validators.required, Validators.maxLength(64)],
    ],
    evidence_kind: ['manual_confirmation' as OutcomeEvidenceKind, [Validators.required]],
    evidence_reference: ['', [Validators.maxLength(255)]],
    correction_reason: ['', [Validators.maxLength(64)]],
    note: ['', [Validators.maxLength(1000)]],
  });
  protected readonly accuracySearch = this.formBuilder.nonNullable.control('', [
    Validators.maxLength(100),
  ]);
  private readonly selectedEntryId = toSignal(this.saleForm.controls.entry_id.valueChanges, {
    initialValue: this.saleForm.controls.entry_id.value,
  });
  protected readonly selectedOutcomeType = toSignal(
    this.saleForm.controls.outcome_type.valueChanges,
    { initialValue: this.saleForm.controls.outcome_type.value },
  );
  protected readonly selectedEntry = computed(
    () =>
      this.projection()?.sale_portfolio_entries.find(
        (entry) => entry.id === this.selectedEntryId(),
      ) ?? null,
  );
  protected readonly currentPurchase = computed(() => {
    const data = this.projection();

    return data?.purchases.find((purchase) => purchase.id === data.current_purchase_id) ?? null;
  });
  protected readonly currentCosts = computed(() => {
    const data = this.projection();

    return (
      data?.cost_snapshots.find((snapshot) => snapshot.id === data.current_cost_snapshot_id) ?? null
    );
  });
  protected readonly currentProfit = computed(() => {
    const data = this.projection();

    return (
      data?.realized_profits.find((profit) => profit.id === data.current_realized_profit_id) ?? null
    );
  });
  protected readonly currentAttribution = computed(() => {
    const data = this.projection();

    return (
      data?.estimate_attributions.find(
        (attribution) => attribution.id === data.current_estimate_attribution_id,
      ) ?? null
    );
  });
  protected readonly currentAccuracy = computed(() => {
    const data = this.projection();

    return (
      data?.estimate_accuracy_reports.find(
        (report) => report.id === data.current_estimate_accuracy_report_id,
      ) ?? null
    );
  });
  protected readonly currentSelectedSale = computed(() => {
    const entryId = this.selectedEntryId();

    return (
      this.projection()
        ?.sales.filter((sale) => sale.sale_portfolio_entry_id === entryId)
        .sort((left, right) => right.sequence - left.sequence)[0] ?? null
    );
  });
  protected readonly showSaleMoney = computed(() => this.selectedOutcomeType() === 'sold');

  constructor() {
    this.markets.catalog().subscribe({
      next: (catalog) => this.marketCatalog.set(catalog),
      error: () =>
        this.error.set(this.i18n.translate('ownedProduct.outcomes.marketReferenceError')),
    });
    effect(() => {
      const productId = this.record().id;

      if (productId !== this.loadedProductId) {
        this.loadedProductId = productId;
        this.load();
      }
    });
  }

  protected reload(): void {
    this.load();
  }

  protected recordPurchase(): void {
    if (!this.canManage() || this.purchaseForm.invalid) {
      this.purchaseForm.markAllAsTouched();
      this.formError.set(this.i18n.translate('ownedProduct.outcomes.purchaseRequired'));
      return;
    }

    const value = this.purchaseForm.getRawValue();
    const amount = this.parseAmount(value.amount, value.currency_code);

    if (
      amount === null ||
      !this.validPastDate(value.occurred_at) ||
      value.evidence_kind === '' ||
      (this.currentPurchase() !== null && this.nullIfBlank(value.correction_reason) === null)
    ) {
      this.formError.set(this.i18n.translate('ownedProduct.outcomes.purchaseRequired'));
      return;
    }

    this.purchaseIdempotencyKey ??= globalThis.crypto.randomUUID();
    this.savingPurchase.set(true);
    this.clearMessages();
    this.ownedProducts
      .recordActualPurchase(this.record().id, {
        expected_current_purchase_id: this.currentPurchase()?.id ?? null,
        amount_minor: amount,
        currency_code: value.currency_code,
        reporting_currency_code: value.reporting_currency_code,
        occurred_at: new Date(value.occurred_at).toISOString(),
        evidence_kind: value.evidence_kind,
        evidence_reference: this.nullIfBlank(value.evidence_reference),
        correction_reason: this.nullIfBlank(value.correction_reason),
        note: this.nullIfBlank(value.note),
        idempotency_key: this.purchaseIdempotencyKey,
      })
      .pipe(finalize(() => this.savingPurchase.set(false)))
      .subscribe({
        next: (response) => {
          this.projection.set(response.data);
          this.purchaseIdempotencyKey = null;
          this.purchaseForm.reset({
            amount: '',
            currency_code: '',
            reporting_currency_code: '',
            occurred_at: '',
            evidence_kind: '',
            evidence_reference: '',
            correction_reason: '',
            note: '',
          });
          this.success.set(
            this.i18n.translate(
              response.meta.created
                ? 'ownedProduct.outcomes.purchaseRecorded'
                : 'ownedProduct.outcomes.commandReplayed',
            ),
          );
        },
        error: (error: unknown) =>
          this.formError.set(
            apiErrorMessage(error, this.i18n.translate('ownedProduct.outcomes.purchaseSaveError')),
          ),
      });
  }

  protected recordCosts(): void {
    if (!this.canManage() || this.costForm.invalid) {
      this.costForm.markAllAsTouched();
      this.formError.set(this.i18n.translate('ownedProduct.outcomes.costRequired'));
      return;
    }

    const value = this.costForm.getRawValue();
    const anyKnown = COST_CATEGORIES.some((category) => value[category].trim() !== '');

    if (
      value.reporting_currency_code === '' ||
      (anyKnown &&
        (value.source_currency_code === '' ||
          value.evidence_kind === '' ||
          !this.validPastDate(value.occurred_at))) ||
      (this.currentCosts() !== null && this.nullIfBlank(value.correction_reason) === null)
    ) {
      this.formError.set(this.i18n.translate('ownedProduct.outcomes.costRequired'));
      return;
    }

    let items: readonly ActualCostItemInput[];

    try {
      items = COST_CATEGORIES.map((category) => {
        const rawAmount = value[category].trim();

        if (rawAmount === '') {
          return {
            category,
            is_known: false,
            amount_minor: null,
            currency_code: null,
            occurred_at: null,
            evidence_kind: null,
            evidence_reference: null,
            note: null,
          };
        }

        const amount = this.parseAmount(rawAmount, value.source_currency_code);

        if (amount === null) {
          throw new Error('invalid-cost');
        }

        return {
          category,
          is_known: true,
          amount_minor: amount,
          currency_code: value.source_currency_code,
          occurred_at: new Date(value.occurred_at).toISOString(),
          evidence_kind: value.evidence_kind as OutcomeEvidenceKind,
          evidence_reference: this.nullIfBlank(value.evidence_reference),
          note: null,
        };
      });
    } catch {
      this.formError.set(this.i18n.translate('ownedProduct.outcomes.costRequired'));
      return;
    }

    this.costIdempotencyKey ??= globalThis.crypto.randomUUID();
    this.savingCosts.set(true);
    this.clearMessages();
    this.ownedProducts
      .recordActualCostSnapshot(this.record().id, {
        expected_current_cost_snapshot_id: this.currentCosts()?.id ?? null,
        reporting_currency_code: value.reporting_currency_code,
        items,
        correction_reason: this.nullIfBlank(value.correction_reason),
        note: this.nullIfBlank(value.note),
        idempotency_key: this.costIdempotencyKey,
      })
      .pipe(finalize(() => this.savingCosts.set(false)))
      .subscribe({
        next: (response) => {
          this.projection.set(response.data);
          this.costIdempotencyKey = null;
          this.costForm.reset({
            reporting_currency_code: '',
            source_currency_code: '',
            occurred_at: '',
            evidence_kind: '',
            evidence_reference: '',
            transport: '',
            repair: '',
            platform_fees: '',
            payment_fees: '',
            customs: '',
            tax: '',
            marketing: '',
            other_costs: '',
            correction_reason: '',
            note: '',
          });
          this.success.set(
            this.i18n.translate(
              response.meta.created
                ? 'ownedProduct.outcomes.costsRecorded'
                : 'ownedProduct.outcomes.commandReplayed',
            ),
          );
        },
        error: (error: unknown) =>
          this.formError.set(
            apiErrorMessage(error, this.i18n.translate('ownedProduct.outcomes.costSaveError')),
          ),
      });
  }

  protected recordSale(): void {
    const entry = this.selectedEntry();
    const currentEvent = entry?.current_event;
    const value = this.saleForm.getRawValue();

    if (
      !this.canManage() ||
      this.saleForm.invalid ||
      entry === null ||
      currentEvent === null ||
      currentEvent === undefined ||
      value.outcome_type === '' ||
      value.evidence_kind === '' ||
      !this.validPastDate(value.occurred_at) ||
      (this.currentSelectedSale() !== null && this.nullIfBlank(value.correction_reason) === null)
    ) {
      this.saleForm.markAllAsTouched();
      this.formError.set(this.i18n.translate('ownedProduct.outcomes.saleRequired'));
      return;
    }

    let amount: number | null = null;

    if (value.outcome_type === 'sold') {
      amount = this.parseAmount(value.amount, value.currency_code);

      if (amount === null || amount <= 0 || value.reporting_currency_code === '') {
        this.formError.set(this.i18n.translate('ownedProduct.outcomes.saleMoneyRequired'));
        return;
      }
    } else if (this.nullIfBlank(value.reason_code) === null) {
      this.formError.set(this.i18n.translate('ownedProduct.outcomes.saleReasonRequired'));
      return;
    }

    this.saleIdempotencyKey ??= globalThis.crypto.randomUUID();
    this.savingSale.set(true);
    this.clearMessages();
    this.ownedProducts
      .recordActualSale(this.record().id, entry.id, {
        expected_current_sale_id: this.currentSelectedSale()?.id ?? null,
        sale_portfolio_event_id: currentEvent.id,
        outcome_type: value.outcome_type,
        amount_minor: amount,
        currency_code: value.outcome_type === 'sold' ? value.currency_code : null,
        reporting_currency_code:
          value.outcome_type === 'sold' ? value.reporting_currency_code : null,
        occurred_at: new Date(value.occurred_at).toISOString(),
        evidence_kind: value.evidence_kind,
        evidence_reference: this.nullIfBlank(value.evidence_reference),
        reason_code: value.outcome_type === 'sold' ? null : this.nullIfBlank(value.reason_code),
        correction_reason: this.nullIfBlank(value.correction_reason),
        note: this.nullIfBlank(value.note),
        idempotency_key: this.saleIdempotencyKey,
      })
      .pipe(finalize(() => this.savingSale.set(false)))
      .subscribe({
        next: (response) => {
          this.projection.set(response.data);
          this.saleIdempotencyKey = null;
          this.saleForm.reset({
            entry_id: '',
            outcome_type: '',
            amount: '',
            currency_code: '',
            reporting_currency_code: '',
            occurred_at: '',
            evidence_kind: '',
            evidence_reference: '',
            reason_code: '',
            correction_reason: '',
            note: '',
          });
          this.success.set(
            this.i18n.translate(
              response.meta.created
                ? 'ownedProduct.outcomes.saleRecorded'
                : 'ownedProduct.outcomes.commandReplayed',
            ),
          );
        },
        error: (error: unknown) =>
          this.formError.set(
            apiErrorMessage(error, this.i18n.translate('ownedProduct.outcomes.saleSaveError')),
          ),
      });
  }

  protected recordAccuracy(): void {
    const profit = this.currentProfit();
    const value = this.accuracyForm.getRawValue();
    const candidate =
      this.accuracyCandidates().find((item) => item.analysis_id === value.analysis_id) ?? null;

    if (
      !this.canManage() ||
      profit === null ||
      candidate === null ||
      this.accuracyForm.invalid ||
      (this.currentAttribution() !== null && this.nullIfBlank(value.correction_reason) === null)
    ) {
      this.accuracyForm.markAllAsTouched();
      this.formError.set(this.i18n.translate('ownedProduct.outcomes.accuracyRequired'));
      return;
    }

    this.accuracyIdempotencyKey ??= globalThis.crypto.randomUUID();
    this.savingAccuracy.set(true);
    this.clearMessages();
    this.ownedProducts
      .recordEstimateAccuracyAttribution(this.record().id, {
        expected_current_attribution_id: this.currentAttribution()?.id ?? null,
        expected_current_accuracy_report_id: this.currentAccuracy()?.id ?? null,
        realized_profit_id: profit.id,
        analysis_id: candidate.analysis_id,
        profit_estimate_id: candidate.profit_estimate_id,
        reason_code: value.reason_code.trim(),
        evidence_kind: value.evidence_kind,
        evidence_reference: this.nullIfBlank(value.evidence_reference),
        correction_reason: this.nullIfBlank(value.correction_reason),
        note: this.nullIfBlank(value.note),
        idempotency_key: this.accuracyIdempotencyKey,
      })
      .pipe(finalize(() => this.savingAccuracy.set(false)))
      .subscribe({
        next: (response) => {
          this.projection.set(response.data);
          this.accuracyIdempotencyKey = null;
          this.accuracyForm.reset({
            analysis_id: '',
            reason_code: 'original_buy_estimate_confirmed',
            evidence_kind: 'manual_confirmation',
            evidence_reference: '',
            correction_reason: '',
            note: '',
          });
          this.success.set(
            this.i18n.translate(
              response.meta.created
                ? 'ownedProduct.outcomes.accuracyRecorded'
                : 'ownedProduct.outcomes.commandReplayed',
            ),
          );
        },
        error: (error: unknown) =>
          this.formError.set(
            apiErrorMessage(error, this.i18n.translate('ownedProduct.outcomes.accuracySaveError')),
          ),
      });
  }

  protected searchAccuracyCandidates(): void {
    if (this.accuracySearch.invalid) {
      this.accuracySearch.markAsTouched();
      return;
    }

    this.accuracyForm.controls.analysis_id.reset('');
    this.loadAccuracyCandidates(this.accuracySearch.value);
  }

  protected allowedOutcomeTypes(
    entry: SalePortfolioEntry | null,
  ): readonly ActualSaleOutcomeType[] {
    if (entry === null || entry.current_event === null) {
      return [];
    }

    if (['listed', 'reserved'].includes(entry.current_status)) {
      return ['sold'];
    }

    if (['withdrawn', 'expired'].includes(entry.current_status)) {
      return ['cancelled', 'no_sale'];
    }

    return [];
  }

  protected money(amount: number | null, currencyCode: string | null): string {
    return this.i18n.formatMoney(amount, currencyCode, this.minorUnit(currencyCode));
  }

  protected date(value: string | null): string {
    return value === null
      ? this.i18n.translate('common.unknown')
      : this.i18n.formatDate(value, {
          dateStyle: 'medium',
          timeStyle: 'short',
        });
  }

  protected duration(seconds: number): string {
    const days = Math.floor(seconds / 86400);

    return this.i18n.translate('ownedProduct.outcomes.durationDays', {
      count: days,
    });
  }

  protected ratio(basisPoints: number | null): string {
    return basisPoints === null
      ? this.i18n.translate('common.unknown')
      : `${this.i18n.formatNumber(basisPoints / 100, {
          maximumFractionDigits: 2,
        })}%`;
  }

  protected accuracyMetricLabel(metric: string): string {
    const keys: Readonly<Record<string, TranslationKey>> = {
      purchase_price: 'ownedProduct.outcomes.accuracy.purchase',
      additional_costs: 'ownedProduct.outcomes.accuracy.costs',
      sale_price: 'ownedProduct.outcomes.accuracy.sale',
      net_profit: 'ownedProduct.outcomes.accuracy.profit',
    };

    return this.i18n.translate(keys[metric] ?? 'ownedProduct.outcomes.accuracy.unknownMetric', {
      code: metric,
    });
  }

  protected metricEntries(
    metrics: Readonly<Record<string, EstimateAccuracyMetric>>,
  ): readonly [string, EstimateAccuracyMetric][] {
    return Object.entries(metrics);
  }

  protected accuracyStatusLabel(status: string): string {
    const keys: Readonly<Record<string, TranslationKey>> = {
      calculated: 'ownedProduct.outcomes.accuracy.status.calculated',
      partial: 'ownedProduct.outcomes.accuracy.status.partial',
      unavailable: 'ownedProduct.outcomes.accuracy.status.unavailable',
    };

    return this.i18n.translate(keys[status] ?? 'ownedProduct.outcomes.accuracy.status.unavailable');
  }

  protected accuracyReasonLabel(code: string): string {
    const keys: Readonly<Record<string, TranslationKey>> = {
      identity_currency_conversion: 'ownedProduct.outcomes.accuracy.reason.identity',
      dated_exchange_rate_resolved: 'ownedProduct.outcomes.accuracy.reason.datedRate',
      monetary_accuracy_calculated: 'ownedProduct.outcomes.accuracy.reason.calculated',
      exchange_rate_missing: 'ownedProduct.outcomes.accuracy.reason.exchangeRateMissing',
      exchange_rate_stale: 'ownedProduct.outcomes.accuracy.reason.exchangeRateStale',
      additional_cost_categories_not_comparable:
        'ownedProduct.outcomes.accuracy.reason.costCategories',
      expected_sale_duration_unavailable:
        'ownedProduct.outcomes.accuracy.reason.durationUnavailable',
    };

    if (code.endsWith('_percentage_error_zero_denominator')) {
      return this.i18n.translate('ownedProduct.outcomes.accuracy.reason.zeroDenominator');
    }

    if (code.endsWith('_percentage_error_outside_supported_range')) {
      return this.i18n.translate('ownedProduct.outcomes.accuracy.reason.percentageRange');
    }

    return this.i18n.translate(keys[code] ?? 'ownedProduct.outcomes.accuracy.reason.unmapped', {
      code,
    });
  }

  protected costLabel(category: ActualCostCategory): string {
    return this.i18n.translate(`ownedProduct.outcomes.cost.${category}` as TranslationKey);
  }

  protected evidenceLabel(kind: OutcomeEvidenceKind): string {
    return this.i18n.translate(`ownedProduct.outcomes.evidence.${kind}` as TranslationKey);
  }

  protected outcomeLabel(type: ActualSaleOutcomeType): string {
    return this.i18n.translate(`ownedProduct.outcomes.sale.${type}` as TranslationKey);
  }

  protected unknownLabel(code: string): string {
    const keys: Readonly<Record<string, TranslationKey>> = {
      actual_purchase_missing: 'ownedProduct.outcomes.unknown.actualPurchase',
      actual_cost_snapshot_missing: 'ownedProduct.outcomes.unknown.costSnapshot',
      actual_costs_incomplete: 'ownedProduct.outcomes.unknown.costsIncomplete',
      actual_sale_missing: 'ownedProduct.outcomes.unknown.actualSale',
      purchase_cost_reporting_currency_mismatch:
        'ownedProduct.outcomes.unknown.purchaseCostCurrency',
      sale_cost_reporting_currency_mismatch: 'ownedProduct.outcomes.unknown.saleCostCurrency',
      realized_profit_unavailable: 'ownedProduct.outcomes.unknown.profitUnavailable',
    };

    return this.i18n.translate(keys[code] ?? 'ownedProduct.outcomes.unknown.unmapped', { code });
  }

  protected shortHash(hash: string): string {
    return `${hash.slice(0, 10)}…${hash.slice(-6)}`;
  }

  private load(): void {
    this.loading.set(true);
    this.error.set(null);
    this.ownedProducts
      .outcomes(this.record().id)
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: (projection) => {
          this.projection.set(projection);

          if (projection.current_realized_profit_id !== null) {
            this.loadAccuracyCandidates();
          }
        },
        error: (error: unknown) =>
          this.error.set(
            apiErrorMessage(error, this.i18n.translate('ownedProduct.outcomes.loadError')),
          ),
      });
  }

  private loadAccuracyCandidates(search: string | null = null): void {
    this.loadingCandidates.set(true);
    this.ownedProducts
      .estimateAccuracyCandidates(this.record().id, search)
      .pipe(finalize(() => this.loadingCandidates.set(false)))
      .subscribe({
        next: (response) => this.accuracyCandidates.set(response.data),
        error: (error: unknown) =>
          this.formError.set(
            apiErrorMessage(
              error,
              this.i18n.translate('ownedProduct.outcomes.accuracyCandidatesError'),
            ),
          ),
      });
  }

  private parseAmount(value: string, currencyCode: string): number | null {
    const minorUnit = this.minorUnit(currencyCode);

    if (minorUnit === null) {
      return null;
    }

    try {
      return parseMoneyToMinor(value, minorUnit);
    } catch {
      return null;
    }
  }

  private minorUnit(currencyCode: string | null): number | null {
    if (currencyCode === null || currencyCode === '') {
      return null;
    }

    return (
      this.marketCatalog()?.currencies.find((currency) => currency.code === currencyCode)
        ?.minor_unit ?? null
    );
  }

  private validPastDate(value: string): boolean {
    const timestamp = new Date(value).getTime();

    return Number.isFinite(timestamp) && timestamp <= Date.now();
  }

  private nullIfBlank(value: string): string | null {
    const trimmed = value.trim();

    return trimmed === '' ? null : trimmed;
  }

  private clearMessages(): void {
    this.formError.set(null);
    this.success.set(null);
  }
}
