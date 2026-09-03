import { Component, computed, effect, inject, input, output, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { finalize } from 'rxjs';

import { apiErrorMessage } from '../../../core/api/api-error';
import { AnalysisCodeLabelService } from '../../../core/i18n/analysis-code-label.service';
import { I18nService } from '../../../core/i18n/i18n.service';
import { TranslatePipe } from '../../../core/i18n/translate.pipe';
import { parseMoneyToMinor } from '../../../core/listing-money';
import { MarketReferenceCatalog } from '../../../core/market.models';
import { MarketReferenceService } from '../../../core/market-reference.service';
import {
  OwnedProduct,
  OwnedProductCondition,
  SellComparableInput,
  SellComparableRecord,
  SellComparableSelectionItem,
  SellPriceBand,
  SellPriceIntelligence,
} from '../../../core/owned-product.models';
import { OwnedProductService } from '../../../core/owned-product.service';
import { SellComparableMarketNormalizationComponent } from './sell-comparable-market-normalization/sell-comparable-market-normalization.component';
import { SellJourneyPanelState } from './sell-journey-state';

@Component({
  selector: 'app-owned-product-price-intelligence-panel',
  imports: [
    ReactiveFormsModule,
    TranslatePipe,
    SellComparableMarketNormalizationComponent,
  ],
  templateUrl: './owned-product-price-intelligence-panel.component.html',
  styleUrl: './owned-product-price-intelligence-panel.component.scss',
})
export class OwnedProductPriceIntelligencePanelComponent {
  private readonly ownedProducts = inject(OwnedProductService);
  private readonly markets = inject(MarketReferenceService);
  private readonly formBuilder = inject(FormBuilder);
  private readonly i18n = inject(I18nService);
  private readonly codeLabels = inject(AnalysisCodeLabelService);
  private loadedKey: string | null = null;

  readonly record = input.required<OwnedProduct>();
  readonly canManage = input(false);
  readonly journeyState = output<SellJourneyPanelState>();

  protected readonly intelligence = signal<SellPriceIntelligence | null>(null);
  protected readonly marketCatalog = signal<MarketReferenceCatalog | null>(null);
  protected readonly loading = signal(true);
  protected readonly saving = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly formError = signal<string | null>(null);
  protected readonly priceError = signal<string | null>(null);
  protected readonly success = signal<string | null>(null);
  protected readonly canAdd = computed(
    () =>
      this.canManage() &&
      this.record().status === 'ready' &&
      this.record().current_assessment?.status === 'ready' &&
      this.intelligence()?.assessment_current === true,
  );
  protected readonly form = this.formBuilder.nonNullable.group({
    marketplace_name: ['', [Validators.required, Validators.maxLength(160)]],
    source_url: ['', [Validators.maxLength(2048)]],
    external_id: ['', [Validators.maxLength(128)]],
    title: ['', [Validators.required, Validators.maxLength(240)]],
    description: ['', [Validators.maxLength(50000)]],
    listing_type: ['product', [Validators.required]],
    condition_code: ['unknown', [Validators.required]],
    seller_type: ['unknown', [Validators.required]],
    price: ['', [Validators.required, Validators.maxLength(32)]],
    currency_code: ['', [Validators.required]],
    country_code: ['', [Validators.required]],
    location: ['', [Validators.maxLength(255)]],
    included_accessories: ['', [Validators.maxLength(4000)]],
    missing_accessories: ['', [Validators.maxLength(4000)]],
    published_at: [''],
    observed_at: ['', [Validators.required]],
  });

  constructor() {
    this.markets.catalog().subscribe({
      next: (catalog) => {
        this.marketCatalog.set(catalog);
      },
      error: () => {
        this.error.set(
          this.i18n.translate('ownedProduct.pricing.marketReferenceError'),
        );
      },
    });
    effect(() => {
      const record = this.record();
      const key = `${record.id}:${record.current_assessment?.id ?? 'none'}`;

      if (key !== this.loadedKey) {
        this.loadedKey = key;
        this.load();
      }
    });
    effect(() => {
      this.record();
      this.marketCatalog();
      this.applyDefaults();
    });
    effect(() => this.journeyState.emit(this.panelJourneyState()));
  }

  protected reload(): void {
    this.load();
  }

  protected submit(): void {
    const product = this.record();
    const assessment = product.current_assessment;

    if (!this.canAdd() || assessment === null || assessment === undefined) {
      this.formError.set(
        this.i18n.translate('ownedProduct.pricing.currentAssessmentRequired'),
      );
      return;
    }

    if (this.form.invalid) {
      this.form.markAllAsTouched();
      this.formError.set(
        this.i18n.translate('ownedProduct.pricing.requiredFields'),
      );
      return;
    }

    const value = this.form.getRawValue();

    if (value.source_url.trim() === '' && value.external_id.trim() === '') {
      this.formError.set(
        this.i18n.translate('ownedProduct.pricing.sourceIdentityRequired'),
      );
      return;
    }

    const minorUnit = this.minorUnit(value.currency_code);
    let amount: number;
    this.priceError.set(null);

    try {
      const parsed = parseMoneyToMinor(value.price, minorUnit);

      if (parsed === null || parsed < 1) {
        this.priceError.set(
          this.i18n.translate('ownedProduct.pricing.positivePrice'),
        );
        return;
      }

      amount = parsed;
    } catch {
      this.priceError.set(
        this.i18n.translate('ownedProduct.pricing.validPrice'),
      );
      return;
    }

    const observedAt = this.isoDate(value.observed_at);
    const publishedAt =
      value.published_at === '' ? null : this.isoDate(value.published_at);

    if (
      observedAt === null ||
      (value.published_at !== '' && publishedAt === null)
    ) {
      this.formError.set(
        this.i18n.translate('ownedProduct.pricing.validTimestamps'),
      );
      return;
    }

    const input: SellComparableInput = {
      owned_product_assessment_id: assessment.id,
      marketplace_source_key: 'manual',
      product_variant_id: assessment.product?.variant_id ?? null,
      source_url: this.nullIfBlank(value.source_url),
      external_id: this.nullIfBlank(value.external_id),
      marketplace_name: value.marketplace_name.trim(),
      title: value.title.trim(),
      description: this.nullIfBlank(value.description),
      listing_type: value.listing_type as SellComparableInput['listing_type'],
      condition_code: value.condition_code as OwnedProductCondition,
      seller_type: value.seller_type as SellComparableInput['seller_type'],
      asking_price_minor: amount,
      currency_code: value.currency_code,
      country_code: value.country_code,
      location: this.nullIfBlank(value.location),
      included_accessories: this.labels(value.included_accessories),
      missing_accessories: this.labels(value.missing_accessories),
      published_at: publishedAt,
      observed_at: observedAt,
    };

    this.saving.set(true);
    this.formError.set(null);
    this.success.set(null);
    this.ownedProducts
      .createSellComparable(product.id, input)
      .pipe(finalize(() => this.saving.set(false)))
      .subscribe({
        next: (response) => {
          this.success.set(
            this.i18n.translate(
              response.meta.created
                ? 'ownedProduct.pricing.comparableRecorded'
                : 'ownedProduct.pricing.comparableReplayed',
            ),
          );
          this.resetForm(input.country_code, input.currency_code);
          this.load(true);
        },
        error: (error: unknown) => {
          this.formError.set(
            apiErrorMessage(
              error,
              this.i18n.translate('ownedProduct.pricing.saveError'),
            ),
          );
        },
      });
  }

  protected money(
    amountMinor: number | null,
    currencyCode: string,
  ): string {
    return this.i18n.formatMoney(
      amountMinor,
      currencyCode,
      this.minorUnit(currencyCode),
    );
  }

  protected recordMoney(record: SellComparableRecord): string {
    return this.money(record.asking_price_minor, record.currency_code);
  }

  protected itemMoney(item: SellComparableSelectionItem): string {
    return this.money(
      item.evidence.asking_price_minor,
      item.evidence.currency_code,
    );
  }

  protected bandRange(
    band: SellPriceBand,
    low: number | null,
    high: number | null,
  ): string {
    if (low === null || high === null) {
      return this.i18n.translate('common.pending');
    }

    return `${this.money(low, band.target_currency_code)} – ${this.money(
      high,
      band.target_currency_code,
    )}`;
  }

  protected percentage(value: number | null): string {
    return value === null
      ? this.i18n.translate('common.pending')
      : this.i18n.formatNumber(value / 10000, {
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

  protected countryName(code: string): string {
    return this.i18n.regionName(code, code);
  }

  protected currencyName(code: string, fallback: string): string {
    return this.i18n.currencyName(code, fallback);
  }

  protected code(value: string): string {
    return this.codeLabels.label(value);
  }

  protected conditionLabel(value: OwnedProductCondition): string {
    return this.i18n.translate(`ownedProduct.condition.${value}`);
  }

  protected entries(
    values: Readonly<Record<string, number>>,
  ): readonly { readonly key: string; readonly value: number }[] {
    return Object.entries(values).map(([key, value]) => ({ key, value }));
  }

  private load(preserveMessages = false): void {
    this.loading.set(true);
    this.error.set(null);

    if (!preserveMessages) {
      this.success.set(null);
    }

    this.ownedProducts
      .sellPriceIntelligence(this.record().id)
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: (intelligence) => {
          this.intelligence.set(intelligence);
          this.applyDefaults();
        },
        error: (error: unknown) => {
          this.intelligence.set(null);
          this.error.set(
            apiErrorMessage(
              error,
              this.i18n.translate('ownedProduct.pricing.loadError'),
            ),
          );
        },
      });
  }

  private panelJourneyState(): SellJourneyPanelState {
    if (this.loading()) {
      return 'loading';
    }

    const intelligence = this.intelligence();

    if (intelligence === null) {
      return this.error() === null ? 'blocked' : 'error';
    }

    if (
      intelligence.current_price_bands.some(
        (band) => band.status === 'ready' || band.status === 'low_confidence',
      )
    ) {
      return 'complete';
    }

    return intelligence.assessment_current &&
      this.record().current_assessment?.status === 'ready'
      ? 'ready'
      : 'blocked';
  }

  private applyDefaults(): void {
    if (this.form.controls.country_code.value !== '') {
      return;
    }

    const product = this.record();
    const country = product.target_countries[0];
    const currency =
      country?.currency_code ??
      this.marketCatalog()
        ?.continents.flatMap((continent) => continent.countries)
        .find((candidate) => candidate.code === country?.code)?.currency_code ??
      '';
    this.resetForm(country?.code ?? '', currency ?? '');
  }

  private resetForm(countryCode: string, currencyCode: string): void {
    const assessment = this.record().current_assessment;
    this.form.reset({
      marketplace_name: '',
      source_url: '',
      external_id: '',
      title: '',
      description: '',
      listing_type: 'product',
      condition_code: assessment?.condition ?? 'unknown',
      seller_type: 'unknown',
      price: '',
      currency_code: currencyCode,
      country_code: countryCode,
      location: '',
      included_accessories: (assessment?.included_accessories ?? []).join('\n'),
      missing_accessories: (assessment?.missing_accessories ?? []).join('\n'),
      published_at: '',
      observed_at: this.localDateTime(new Date()),
    });
    this.priceError.set(null);
    this.formError.set(null);
  }

  private minorUnit(currencyCode: string): number {
    return (
      this.marketCatalog()?.currencies.find(
        (currency) => currency.code === currencyCode,
      )?.minor_unit ?? 2
    );
  }

  private labels(value: string): readonly string[] {
    return [
      ...new Set(
        value
          .split(/\r?\n/)
          .map((label) => label.trim())
          .filter(Boolean),
      ),
    ];
  }

  private nullIfBlank(value: string): string | null {
    const trimmed = value.trim();

    return trimmed === '' ? null : trimmed;
  }

  private isoDate(value: string): string | null {
    const parsed = new Date(value);

    return Number.isNaN(parsed.getTime()) ? null : parsed.toISOString();
  }

  private localDateTime(value: Date): string {
    const local = new Date(value.getTime() - value.getTimezoneOffset() * 60000);

    return local.toISOString().slice(0, 16);
  }
}
