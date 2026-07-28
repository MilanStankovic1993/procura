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
  OwnedProduct,
  SalePortfolioEntry,
  SalePortfolioEventInput,
  SalePortfolioEventType,
  SalePortfolioProjection,
  SalePortfolioStatus,
  SellListingDraft,
} from '../../../core/owned-product.models';
import { OwnedProductService } from '../../../core/owned-product.service';

@Component({
  selector: 'app-owned-product-sale-portfolio-panel',
  imports: [ReactiveFormsModule, TranslatePipe],
  templateUrl: './owned-product-sale-portfolio-panel.component.html',
  styleUrl: './owned-product-sale-portfolio-panel.component.scss',
})
export class OwnedProductSalePortfolioPanelComponent {
  private readonly ownedProducts = inject(OwnedProductService);
  private readonly markets = inject(MarketReferenceService);
  private readonly formBuilder = inject(FormBuilder);
  private readonly i18n = inject(I18nService);
  private loadedKey: string | null = null;
  private entryIdempotencyKey: string | null = null;
  private eventIdempotencyKey: string | null = null;

  readonly record = input.required<OwnedProduct>();
  readonly canManage = input(false);

  protected readonly projection = signal<SalePortfolioProjection | null>(null);
  protected readonly marketCatalog = signal<MarketReferenceCatalog | null>(
    null,
  );
  protected readonly loading = signal(true);
  protected readonly savingEntry = signal(false);
  protected readonly savingEvent = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly formError = signal<string | null>(null);
  protected readonly success = signal<string | null>(null);
  protected readonly entryForm = this.formBuilder.nonNullable.group({
    sell_listing_draft_id: ['', [Validators.required]],
  });
  protected readonly eventForm = this.formBuilder.nonNullable.group({
    entry_id: ['', [Validators.required]],
    event_type: ['' as SalePortfolioEventType | '', [Validators.required]],
    marketplace_name: ['', [Validators.maxLength(160)]],
    marketplace_key: ['', [Validators.maxLength(80)]],
    external_listing_id: ['', [Validators.maxLength(128)]],
    external_listing_url: ['', [Validators.maxLength(2048)]],
    advertised_price: ['', [Validators.maxLength(32)]],
    advertised_currency_code: ['', [Validators.maxLength(3)]],
    reason_code: ['', [Validators.maxLength(64)]],
    note: ['', [Validators.maxLength(1000)]],
    occurred_at: ['', [Validators.required]],
  });
  private readonly selectedEntryId = toSignal(
    this.eventForm.controls.entry_id.valueChanges,
    { initialValue: this.eventForm.controls.entry_id.value },
  );
  protected readonly selectedEventType = toSignal(
    this.eventForm.controls.event_type.valueChanges,
    { initialValue: this.eventForm.controls.event_type.value },
  );
  protected readonly selectedEntry = computed(
    () =>
      this.projection()?.entries.find(
        (entry) => entry.id === this.selectedEntryId(),
      ) ?? null,
  );
  protected readonly showPublicationFields = computed(() =>
    ['published', 'relisted'].includes(this.selectedEventType()),
  );
  protected readonly showPriceFields = computed(() =>
    ['published', 'price_changed', 'relisted'].includes(
      this.selectedEventType(),
    ),
  );
  protected readonly canCreateEntry = computed(
    () =>
      this.canManage() &&
      this.record().status === 'ready' &&
      this.projection()?.assessment_current === true &&
      (this.projection()?.available_listing_drafts.length ?? 0) > 0,
  );

  constructor() {
    this.markets.catalog().subscribe({
      next: (catalog) => this.marketCatalog.set(catalog),
      error: () =>
        this.error.set(
          this.i18n.translate('ownedProduct.portfolio.marketReferenceError'),
        ),
    });
    effect(() => {
      const product = this.record();
      const key = `${product.id}:${product.current_assessment?.id ?? 'none'}`;

      if (key !== this.loadedKey) {
        this.loadedKey = key;
        this.load();
      }
    });
  }

  protected reload(): void {
    this.load();
  }

  protected createEntry(): void {
    if (!this.canCreateEntry() || this.entryForm.invalid) {
      this.entryForm.markAllAsTouched();
      this.formError.set(
        this.i18n.translate('ownedProduct.portfolio.entryRequired'),
      );
      return;
    }

    this.entryIdempotencyKey ??= globalThis.crypto.randomUUID();
    this.savingEntry.set(true);
    this.formError.set(null);
    this.success.set(null);
    this.ownedProducts
      .createSalePortfolioEntry(this.record().id, {
        sell_listing_draft_id:
          this.entryForm.controls.sell_listing_draft_id.value,
        idempotency_key: this.entryIdempotencyKey,
      })
      .pipe(finalize(() => this.savingEntry.set(false)))
      .subscribe({
        next: (response) => {
          this.projection.set(response.data);
          this.eventForm.controls.entry_id.setValue(
            response.meta.entry_id,
          );
          this.entryForm.reset({ sell_listing_draft_id: '' });
          this.entryIdempotencyKey = null;
          this.success.set(
            this.i18n.translate(
              response.meta.created
                ? 'ownedProduct.portfolio.entryCreated'
                : 'ownedProduct.portfolio.entryReplayed',
            ),
          );
        },
        error: (error: unknown) => {
          this.formError.set(
            apiErrorMessage(
              error,
              this.i18n.translate(
                'ownedProduct.portfolio.entrySaveError',
              ),
            ),
          );
        },
      });
  }

  protected recordEvent(): void {
    const entry = this.selectedEntry();
    const type = this.selectedEventType();

    if (
      entry === null ||
      type === '' ||
      !entry.allowed_events.includes(type) ||
      this.eventForm.invalid
    ) {
      this.eventForm.markAllAsTouched();
      this.formError.set(
        this.i18n.translate('ownedProduct.portfolio.eventRequired'),
      );
      return;
    }

    const value = this.eventForm.getRawValue();
    const publication = this.showPublicationFields();
    const priceRequired = this.showPriceFields();
    const marketplaceName = this.nullIfBlank(value.marketplace_name);
    const marketplaceKey = this.nullIfBlank(value.marketplace_key);
    const externalId = this.nullIfBlank(value.external_listing_id);
    const externalUrl = this.nullIfBlank(value.external_listing_url);
    const currencyCode = this.nullIfBlank(
      value.advertised_currency_code,
    )?.toUpperCase() ?? null;
    const reasonCode = this.nullIfBlank(value.reason_code);

    if (
      publication &&
      (marketplaceName === null ||
        marketplaceKey === null ||
        externalId === null ||
        externalUrl === null)
    ) {
      this.formError.set(
        this.i18n.translate(
          'ownedProduct.portfolio.publicationRequired',
        ),
      );
      return;
    }

    if (type === 'withdrawn' && reasonCode === null) {
      this.formError.set(
        this.i18n.translate(
          'ownedProduct.portfolio.withdrawalReasonRequired',
        ),
      );
      return;
    }

    let priceMinor: number | null = null;

    if (priceRequired) {
      if (currencyCode === null) {
        this.formError.set(
          this.i18n.translate('ownedProduct.portfolio.priceRequired'),
        );
        return;
      }

      try {
        priceMinor = parseMoneyToMinor(
          value.advertised_price,
          this.minorUnit(currencyCode),
        );
      } catch {
        priceMinor = null;
      }

      if (priceMinor === null || priceMinor < 1) {
        this.formError.set(
          this.i18n.translate('ownedProduct.portfolio.priceRequired'),
        );
        return;
      }
    }

    const occurredAt = this.isoDate(value.occurred_at);

    if (occurredAt === null) {
      this.formError.set(
        this.i18n.translate('ownedProduct.portfolio.eventTimeRequired'),
      );
      return;
    }

    this.eventIdempotencyKey ??= globalThis.crypto.randomUUID();
    const input: SalePortfolioEventInput = {
      expected_current_event_id: entry.current_event_id,
      event_type: type,
      marketplace_name: publication ? marketplaceName : null,
      marketplace_key: publication ? marketplaceKey : null,
      external_listing_id: publication ? externalId : null,
      external_listing_url: publication ? externalUrl : null,
      advertised_price_minor: priceMinor,
      advertised_currency_code: priceRequired ? currencyCode : null,
      reason_code: reasonCode,
      note: this.nullIfBlank(value.note),
      occurred_at: occurredAt,
      idempotency_key: this.eventIdempotencyKey,
    };
    this.savingEvent.set(true);
    this.formError.set(null);
    this.success.set(null);
    this.ownedProducts
      .recordSalePortfolioEvent(this.record().id, entry.id, input)
      .pipe(finalize(() => this.savingEvent.set(false)))
      .subscribe({
        next: (response) => {
          this.projection.set(response.data);
          this.eventIdempotencyKey = null;
          this.resetEventDetails(entry.id);
          this.success.set(
            this.i18n.translate(
              response.meta.created
                ? 'ownedProduct.portfolio.eventCreated'
                : 'ownedProduct.portfolio.eventReplayed',
            ),
          );
        },
        error: (error: unknown) => {
          this.formError.set(
            apiErrorMessage(
              error,
              this.i18n.translate(
                'ownedProduct.portfolio.eventSaveError',
              ),
            ),
          );
        },
      });
  }

  protected entryLabel(entry: SalePortfolioEntry): string {
    return `#${entry.sequence} · ${
      entry.listing_title ??
      this.i18n.translate('ownedProduct.portfolio.untitledDraft')
    }`;
  }

  protected draftLabel(draft: SellListingDraft): string {
    return `#${draft.run_number} · ${draft.title} · ${this.money(
      draft.target_asking_price_minor,
      draft.target_currency_code,
    )}`;
  }

  protected statusLabel(status: SalePortfolioStatus): string {
    return this.i18n.translate(
      `ownedProduct.portfolio.status.${status}` as TranslationKey,
    );
  }

  protected eventLabel(type: SalePortfolioEventType): string {
    return this.i18n.translate(
      `ownedProduct.portfolio.event.${type}` as TranslationKey,
    );
  }

  protected money(amount: number | null, currencyCode: string | null): string {
    if (currencyCode === null) {
      return this.i18n.translate('common.unknown');
    }

    return this.i18n.formatMoney(
      amount,
      currencyCode,
      this.minorUnit(currencyCode),
    );
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
      .salePortfolio(this.record().id)
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: (projection) => {
          this.projection.set(projection);
          const selectedEntry = this.eventForm.controls.entry_id.value;

          if (
            selectedEntry !== '' &&
            !projection.entries.some(
              (entry) => entry.id === selectedEntry,
            )
          ) {
            this.eventForm.controls.entry_id.setValue('');
          }
        },
        error: (error: unknown) => {
          this.projection.set(null);
          this.error.set(
            apiErrorMessage(
              error,
              this.i18n.translate('ownedProduct.portfolio.loadError'),
            ),
          );
        },
      });
  }

  private resetEventDetails(entryId: string): void {
    this.eventForm.reset({
      entry_id: entryId,
      event_type: '',
      marketplace_name: '',
      marketplace_key: '',
      external_listing_id: '',
      external_listing_url: '',
      advertised_price: '',
      advertised_currency_code: '',
      reason_code: '',
      note: '',
      occurred_at: '',
    });
  }

  private isoDate(value: string): string | null {
    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? null : date.toISOString();
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
