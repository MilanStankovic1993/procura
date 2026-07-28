import { Component, computed, effect, inject, input, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { finalize } from 'rxjs';

import { apiErrorMessage } from '../../../core/api/api-error';
import { AnalysisCodeLabelService } from '../../../core/i18n/analysis-code-label.service';
import { I18nService } from '../../../core/i18n/i18n.service';
import { TranslationKey } from '../../../core/i18n/locales/en';
import { TranslatePipe } from '../../../core/i18n/translate.pipe';
import { parseMoneyToMinor } from '../../../core/listing-money';
import { MarketReferenceCatalog } from '../../../core/market.models';
import { MarketReferenceService } from '../../../core/market-reference.service';
import {
  OwnedProduct,
  SellListingDraft,
  SellListingDraftInput,
  SellListingDraftProjection,
  SellListingLanguage,
  SellListingPhotoCheckItem,
  SellPhotoCheckStatus,
  SellPhotoReadinessStatus,
  SellPriceBand,
  SellPriceStrategy,
} from '../../../core/owned-product.models';
import { OwnedProductService } from '../../../core/owned-product.service';

@Component({
  selector: 'app-owned-product-listing-draft-panel',
  imports: [ReactiveFormsModule, TranslatePipe],
  templateUrl: './owned-product-listing-draft-panel.component.html',
  styleUrl: './owned-product-listing-draft-panel.component.scss',
})
export class OwnedProductListingDraftPanelComponent {
  private readonly ownedProducts = inject(OwnedProductService);
  private readonly markets = inject(MarketReferenceService);
  private readonly formBuilder = inject(FormBuilder);
  private readonly i18n = inject(I18nService);
  private readonly codeLabels = inject(AnalysisCodeLabelService);
  private loadedKey: string | null = null;

  readonly record = input.required<OwnedProduct>();
  readonly canManage = input(false);

  protected readonly projection = signal<SellListingDraftProjection | null>(
    null,
  );
  protected readonly marketCatalog = signal<MarketReferenceCatalog | null>(
    null,
  );
  protected readonly loading = signal(true);
  protected readonly saving = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly formError = signal<string | null>(null);
  protected readonly priceError = signal<string | null>(null);
  protected readonly success = signal<string | null>(null);
  protected readonly form = this.formBuilder.nonNullable.group({
    price_band_id: ['', [Validators.required]],
    price_strategy: [
      '' as SellPriceStrategy | '',
      [Validators.required],
    ],
    target_price: ['', [Validators.required, Validators.maxLength(32)]],
    listing_language: ['' as SellListingLanguage | '', [Validators.required]],
    price_override_reason: ['', [Validators.maxLength(1000)]],
  });
  private readonly selectedBandId = toSignal(
    this.form.controls.price_band_id.valueChanges,
    { initialValue: this.form.controls.price_band_id.value },
  );
  protected readonly selectedBand = computed(() => {
    const bandId = this.selectedBandId();

    return (
      this.projection()?.available_price_bands.find(
        (band) => band.id === bandId,
      ) ?? null
    );
  });
  protected readonly canGenerate = computed(
    () =>
      this.canManage() &&
      this.record().status === 'ready' &&
      this.record().current_assessment?.status === 'ready' &&
      this.projection()?.assessment_current === true &&
      (this.projection()?.available_price_bands.length ?? 0) > 0,
  );
  protected readonly currentDraftIds = computed(
    () => new Set(this.projection()?.current_drafts.map((draft) => draft.id)),
  );

  constructor() {
    this.markets.catalog().subscribe({
      next: (catalog) => this.marketCatalog.set(catalog),
      error: () =>
        this.error.set(
          this.i18n.translate('ownedProduct.listing.marketReferenceError'),
        ),
    });
    effect(() => {
      const record = this.record();
      const key = `${record.id}:${record.current_assessment?.id ?? 'none'}`;

      if (key !== this.loadedKey) {
        this.loadedKey = key;
        this.load();
      }
    });
  }

  protected reload(): void {
    this.load();
  }

  protected submit(): void {
    const product = this.record();
    const assessment = product.current_assessment;
    const band = this.selectedBand();

    if (
      !this.canGenerate() ||
      assessment === null ||
      assessment === undefined ||
      band === null
    ) {
      this.formError.set(
        this.i18n.translate('ownedProduct.listing.upstreamRequired'),
      );
      return;
    }

    if (this.form.invalid) {
      this.form.markAllAsTouched();
      this.formError.set(
        this.i18n.translate('ownedProduct.listing.requiredFields'),
      );
      return;
    }

    const value = this.form.getRawValue();
    const minorUnit = this.minorUnit(band.target_currency_code);
    let targetPrice: number;
    this.priceError.set(null);

    try {
      const parsed = parseMoneyToMinor(value.target_price, minorUnit);

      if (parsed === null || parsed < 1) {
        this.priceError.set(
          this.i18n.translate('ownedProduct.listing.positivePrice'),
        );
        return;
      }

      targetPrice = parsed;
    } catch {
      this.priceError.set(
        this.i18n.translate('ownedProduct.listing.validPrice'),
      );
      return;
    }

    const [low, high] = this.range(
      band,
      value.price_strategy as SellPriceStrategy,
    );
    const outside = targetPrice < low || targetPrice > high;
    const overrideReason = this.nullIfBlank(value.price_override_reason);

    if (outside && overrideReason === null) {
      this.formError.set(
        this.i18n.translate('ownedProduct.listing.overrideReasonRequired'),
      );
      return;
    }

    const input: SellListingDraftInput = {
      owned_product_assessment_id: assessment.id,
      sell_price_band_id: band.id,
      target_country_code: band.target_country_code,
      target_currency_code: band.target_currency_code,
      price_strategy: value.price_strategy as SellPriceStrategy,
      target_asking_price_minor: targetPrice,
      listing_language: value.listing_language as SellListingLanguage,
      price_override_reason: overrideReason,
    };
    this.saving.set(true);
    this.formError.set(null);
    this.success.set(null);
    this.ownedProducts
      .createSellListingDraft(product.id, input)
      .pipe(finalize(() => this.saving.set(false)))
      .subscribe({
        next: (response) => {
          this.success.set(
            this.i18n.translate(
              response.meta.created
                ? 'ownedProduct.listing.created'
                : 'ownedProduct.listing.replayed',
            ),
          );
          this.form.controls.target_price.setValue('');
          this.form.controls.price_override_reason.setValue('');
          this.load(true);
        },
        error: (error: unknown) => {
          this.formError.set(
            apiErrorMessage(
              error,
              this.i18n.translate('ownedProduct.listing.saveError'),
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

  protected selectedRange(): string {
    const band = this.selectedBand();

    if (band === null) {
      return this.i18n.translate('common.pending');
    }

    const strategy = this.form.controls.price_strategy.value;

    if (strategy === '') {
      return this.i18n.translate('common.pending');
    }

    const [low, high] = this.range(
      band,
      strategy,
    );

    return `${this.money(low, band.target_currency_code)} – ${this.money(
      high,
      band.target_currency_code,
    )}`;
  }

  protected bandLabel(band: SellPriceBand): string {
    return `${this.countryName(band.target_country_code)} · ${
      band.target_currency_code
    } · #${band.run_number}`;
  }

  protected draftStatus(status: SellListingDraft['status']): string {
    return this.i18n.translate(
      `ownedProduct.listing.status.${status}` as TranslationKey,
    );
  }

  protected photoStatus(status: SellPhotoReadinessStatus): string {
    return this.i18n.translate(
      `ownedProduct.listing.photoStatus.${status}` as TranslationKey,
    );
  }

  protected checkStatus(status: SellPhotoCheckStatus): string {
    return this.i18n.translate(
      `ownedProduct.listing.checkStatus.${status}` as TranslationKey,
    );
  }

  protected checkLabel(item: SellListingPhotoCheckItem): string {
    return this.i18n.translate(
      `ownedProduct.listing.check.${item.check_code}` as TranslationKey,
    );
  }

  protected strategyLabel(strategy: SellPriceStrategy): string {
    return this.i18n.translate(
      `ownedProduct.listing.strategy.${strategy}` as TranslationKey,
    );
  }

  protected languageLabel(language: SellListingLanguage): string {
    return this.i18n.translate(
      `language.${language}` as TranslationKey,
    );
  }

  protected code(value: string): string {
    return this.codeLabels.label(value);
  }

  protected percentage(value: number): string {
    return this.i18n.formatNumber(value / 10000, {
      style: 'percent',
      minimumFractionDigits: 1,
      maximumFractionDigits: 1,
    });
  }

  protected countryName(code: string): string {
    return this.i18n.regionName(code, code);
  }

  protected date(value: string | null): string {
    return value === null
      ? this.i18n.translate('common.unknownDate')
      : this.i18n.formatDate(value, {
          dateStyle: 'medium',
          timeStyle: 'short',
        });
  }

  protected shortHash(hash: string): string {
    return `${hash.slice(0, 10)}…${hash.slice(-6)}`;
  }

  private load(preserveMessages = false): void {
    this.loading.set(true);
    this.error.set(null);

    if (!preserveMessages) {
      this.success.set(null);
    }

    this.ownedProducts
      .sellListingDrafts(this.record().id)
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: (projection) => {
          this.projection.set(projection);
          const selectedId = this.form.controls.price_band_id.value;

          if (
            selectedId !== '' &&
            !projection.available_price_bands.some(
              (band) => band.id === selectedId,
            )
          ) {
            this.form.controls.price_band_id.setValue('');
          }
        },
        error: (error: unknown) => {
          this.projection.set(null);
          this.error.set(
            apiErrorMessage(
              error,
              this.i18n.translate('ownedProduct.listing.loadError'),
            ),
          );
        },
      });
  }

  private range(
    band: SellPriceBand,
    strategy: SellPriceStrategy,
  ): readonly [number, number] {
    const selected = band.bands[strategy];

    return [selected.low_minor ?? 0, selected.high_minor ?? 0];
  }

  private minorUnit(currencyCode: string): number {
    return (
      this.marketCatalog()?.currencies.find(
        (currency) => currency.code === currencyCode,
      )?.minor_unit ?? 2
    );
  }

  private nullIfBlank(value: string): string | null {
    const trimmed = value.trim();

    return trimmed === '' ? null : trimmed;
  }
}
