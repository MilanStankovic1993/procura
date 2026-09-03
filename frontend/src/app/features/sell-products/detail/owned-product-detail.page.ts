import { Component, computed, effect, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { finalize } from 'rxjs';

import { apiErrorMessage } from '../../../core/api/api-error';
import { I18nService } from '../../../core/i18n/i18n.service';
import { TranslationKey } from '../../../core/i18n/locales/en';
import { TranslatePipe } from '../../../core/i18n/translate.pipe';
import {
  CrossBorderPreference,
  DesiredSaleSpeed,
  OwnedProduct,
  OwnedProductCondition,
  OwnedProductImage,
  OwnedProductImageKind,
  OwnedProductStatus,
} from '../../../core/owned-product.models';
import { OwnedProductService } from '../../../core/owned-product.service';
import { OrganizationContextService } from '../../../core/organizations/organization-context.service';
import { OwnedProductAssessmentPanelComponent } from './owned-product-assessment-panel.component';
import { OwnedProductListingDraftPanelComponent } from './owned-product-listing-draft-panel.component';
import { OwnedProductOutcomePanelComponent } from './owned-product-outcome-panel.component';
import { OwnedProductPriceIntelligencePanelComponent } from './owned-product-price-intelligence-panel.component';
import { OwnedProductSalePortfolioPanelComponent } from './owned-product-sale-portfolio-panel.component';
import {
  nextSellJourneyStage,
  SellJourneyPanelState,
  SellJourneyStage,
} from './sell-journey-state';

@Component({
  selector: 'app-owned-product-detail-page',
  imports: [
    ReactiveFormsModule,
    RouterLink,
    TranslatePipe,
    OwnedProductAssessmentPanelComponent,
    OwnedProductPriceIntelligencePanelComponent,
    OwnedProductListingDraftPanelComponent,
    OwnedProductSalePortfolioPanelComponent,
    OwnedProductOutcomePanelComponent,
  ],
  templateUrl: './owned-product-detail.page.html',
  styleUrl: './owned-product-detail.page.scss',
})
export class OwnedProductDetailPage {
  private readonly ownedProducts = inject(OwnedProductService);
  private readonly organizations = inject(OrganizationContextService);
  private readonly route = inject(ActivatedRoute);
  private readonly formBuilder = inject(FormBuilder);
  private readonly i18n = inject(I18nService);
  private loadedContextKey: string | null = null;

  protected readonly record = signal<OwnedProduct | null>(null);
  protected readonly loading = signal(true);
  protected readonly saving = signal(false);
  protected readonly uploading = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly success = signal<TranslationKey | null>(null);
  protected readonly selectedFiles = signal<readonly File[]>([]);
  protected readonly removingImageIds = signal<ReadonlySet<string>>(new Set());
  private readonly pricingJourneyState = signal<SellJourneyPanelState>('loading');
  private readonly listingJourneyState = signal<SellJourneyPanelState>('loading');
  private readonly portfolioJourneyState = signal<SellJourneyPanelState>('loading');
  private readonly outcomeJourneyState = signal<SellJourneyPanelState>('loading');
  protected readonly canManage = computed(
    () =>
      this.organizations
        .activeOrganization()
        ?.capabilities.includes('owned-products.manage') === true,
  );
  protected readonly canMutate = computed(
    () => this.canManage() && this.record()?.status !== 'archived',
  );
  protected readonly journeyStage = computed<SellJourneyStage>(() => {
    const record = this.record();

    if (record === null) {
      return 'prepare';
    }

    return nextSellJourneyStage({
      productStatus: record.status,
      assessmentStatus: record.current_assessment?.status ?? null,
      pricing: this.pricingJourneyState(),
      listing: this.listingJourneyState(),
      portfolio: this.portfolioJourneyState(),
      outcome: this.outcomeJourneyState(),
    });
  });
  protected readonly currentJourneyPanelState = computed<SellJourneyPanelState>(() => {
    const states: Partial<Record<SellJourneyStage, SellJourneyPanelState>> = {
      pricing: this.pricingJourneyState(),
      listing: this.listingJourneyState(),
      portfolio: this.portfolioJourneyState(),
      outcome: this.outcomeJourneyState(),
      complete: 'complete',
      archived: 'blocked',
    };

    return states[this.journeyStage()] ?? 'ready';
  });
  protected readonly lifecycleForm = this.formBuilder.nonNullable.group({
    status: ['draft' as OwnedProductStatus],
    notes: [''],
  });
  protected readonly uploadForm = this.formBuilder.nonNullable.group({
    kind: ['product' as OwnedProductImageKind],
  });

  constructor() {
    effect(() => {
      const organizationId = this.organizations.activeOrganization()?.id ?? null;
      const ownedProductId = this.ownedProductId();
      const contextKey =
        organizationId === null || ownedProductId === null
          ? null
          : `${organizationId}:${ownedProductId}`;

      if (contextKey !== null && contextKey !== this.loadedContextKey) {
        this.loadedContextKey = contextKey;
        this.resetJourneyPanelStates();
        this.load();
      }
    });
  }

  protected retry(): void {
    this.load();
  }

  protected saveLifecycle(): void {
    const record = this.record();

    if (record === null || !this.canMutate() || this.saving()) {
      return;
    }

    const value = this.lifecycleForm.getRawValue();
    this.saving.set(true);
    this.error.set(null);
    this.success.set(null);

    this.ownedProducts
      .updateLifecycle(
        record.id,
        value.status,
        this.nullIfBlank(value.notes),
      )
      .pipe(finalize(() => this.saving.set(false)))
      .subscribe({
        next: () => {
          this.success.set('ownedProduct.detail.saved');
          this.load(true);
        },
        error: (error: unknown) => {
          this.error.set(
            apiErrorMessage(
              error,
              this.i18n.translate('ownedProduct.detail.saveError'),
            ),
          );
        },
      });
  }

  protected selectFiles(event: Event): void {
    const input = event.target as HTMLInputElement;
    const files = Array.from(input.files ?? []);
    const allowedTypes = new Set(['image/jpeg', 'image/png', 'image/webp']);
    const invalid = files.find(
      (file) => !allowedTypes.has(file.type) || file.size > 10 * 1024 * 1024,
    );

    if (invalid !== undefined || files.length > 10) {
      this.error.set(this.i18n.translate('ownedProduct.detail.fileSelectionError'));
      input.value = '';
      this.selectedFiles.set([]);
      return;
    }

    this.error.set(null);
    this.selectedFiles.set(files);
  }

  protected upload(): void {
    const record = this.record();
    const files = this.selectedFiles();

    if (record === null || files.length === 0 || !this.canMutate()) {
      return;
    }

    this.uploading.set(true);
    this.error.set(null);
    this.success.set(null);
    this.ownedProducts
      .uploadImages(record.id, this.uploadForm.controls.kind.value, files)
      .pipe(finalize(() => this.uploading.set(false)))
      .subscribe({
        next: () => {
          this.selectedFiles.set([]);
          this.success.set('ownedProduct.detail.uploaded');
          this.load(true);
        },
        error: (error: unknown) => {
          this.error.set(
            apiErrorMessage(
              error,
              this.i18n.translate('ownedProduct.detail.uploadError'),
            ),
          );
        },
      });
  }

  protected removeImage(image: OwnedProductImage): void {
    const record = this.record();

    if (
      record === null ||
      !this.canMutate() ||
      this.removingImageIds().has(image.id)
    ) {
      return;
    }

    const removing = new Set(this.removingImageIds());
    removing.add(image.id);
    this.removingImageIds.set(removing);
    this.error.set(null);
    this.success.set(null);

    this.ownedProducts
      .deleteImage(record.id, image.id)
      .pipe(
        finalize(() => {
          const next = new Set(this.removingImageIds());
          next.delete(image.id);
          this.removingImageIds.set(next);
        }),
      )
      .subscribe({
        next: () => {
          this.record.update((current) =>
            current === null
              ? null
              : {
                  ...current,
                  images: current.images?.filter((item) => item.id !== image.id),
                  image_count: Math.max(0, current.image_count - 1),
                },
          );
          this.success.set('ownedProduct.detail.removed');
        },
        error: (error: unknown) => {
          this.error.set(
            apiErrorMessage(
              error,
              this.i18n.translate('ownedProduct.detail.removeError'),
            ),
          );
        },
      });
  }

  protected title(record: OwnedProduct): string {
    const value = [record.brand_name, record.model_name].filter(Boolean).join(' ');

    return value || this.i18n.translate('ownedProduct.common.unknownProduct');
  }

  protected statusLabel(status: OwnedProductStatus): string {
    return this.i18n.translate(
      `ownedProduct.status.${status}` as TranslationKey,
    );
  }

  protected conditionLabel(condition: OwnedProductCondition): string {
    return this.i18n.translate(
      `ownedProduct.condition.${condition}` as TranslationKey,
    );
  }

  protected borderLabel(preference: CrossBorderPreference): string {
    return this.i18n.translate(
      `ownedProduct.border.${preference}` as TranslationKey,
    );
  }

  protected speedLabel(speed: DesiredSaleSpeed): string {
    return this.i18n.translate(`ownedProduct.speed.${speed}` as TranslationKey);
  }

  protected imageKindLabel(kind: OwnedProductImageKind): string {
    return this.i18n.translate(`ownedProduct.image.${kind}` as TranslationKey);
  }

  protected listLabel(value: readonly string[] | null): string {
    if (value === null) {
      return this.i18n.translate('common.unknown');
    }

    return value.length === 0
      ? this.i18n.translate('ownedProduct.common.noneConfirmed')
      : value.join(', ');
  }

  protected purchaseHistory(record: OwnedProduct): string {
    if (!record.purchase_history_known) {
      return this.i18n.translate('common.unknown');
    }

    return record.purchase_history ??
      this.i18n.translate('ownedProduct.common.noneConfirmed');
  }

  protected targetCountries(codes: readonly string[]): string {
    return codes
      .map((code) => this.i18n.regionName(code, code))
      .join(', ');
  }

  protected date(value: string | null): string {
    return value === null
      ? this.i18n.translate('common.unknownDate')
      : this.i18n.formatDate(value, { dateStyle: 'medium', timeStyle: 'short' });
  }

  protected fileSize(size: number): string {
    if (size < 1024 * 1024) {
      return `${this.i18n.formatNumber(Math.max(1, Math.round(size / 1024)))} KB`;
    }

    return `${this.i18n.formatNumber(size / (1024 * 1024), {
      minimumFractionDigits: 1,
      maximumFractionDigits: 1,
    })} MB`;
  }

  protected shortHash(hash: string): string {
    return `${hash.slice(0, 10)}…${hash.slice(-6)}`;
  }

  protected updateJourneyState(
    stage: 'pricing' | 'listing' | 'portfolio' | 'outcome',
    state: SellJourneyPanelState,
  ): void {
    const signals = {
      pricing: this.pricingJourneyState,
      listing: this.listingJourneyState,
      portfolio: this.portfolioJourneyState,
      outcome: this.outcomeJourneyState,
    };

    signals[stage].set(state);
  }

  protected journeyTitleKey(): TranslationKey {
    const state = this.currentJourneyPanelState();

    if (state === 'loading') {
      return 'ownedProduct.journey.loading.title';
    }

    if (state === 'error') {
      return 'ownedProduct.journey.error.title';
    }

    return `ownedProduct.journey.${this.journeyStage()}.title` as TranslationKey;
  }

  protected journeyDescriptionKey(): TranslationKey {
    const state = this.currentJourneyPanelState();

    if (state === 'loading') {
      return 'ownedProduct.journey.loading.description';
    }

    if (state === 'error') {
      return 'ownedProduct.journey.error.description';
    }

    return `ownedProduct.journey.${this.journeyStage()}.description` as TranslationKey;
  }

  protected journeyActionKey(): TranslationKey {
    if (this.currentJourneyPanelState() === 'error') {
      return 'ownedProduct.journey.error.action';
    }

    return `ownedProduct.journey.${this.journeyStage()}.action` as TranslationKey;
  }

  protected journeyTarget(): string {
    const targets: Readonly<Record<SellJourneyStage, string>> = {
      prepare: '#sell-lifecycle',
      assessment: '#sell-assessment',
      pricing: '#sell-pricing',
      listing: '#sell-listing',
      portfolio: '#sell-portfolio',
      outcome: '#sell-outcome',
      complete: '#sell-outcome',
      archived: '#sell-lifecycle',
    };

    return targets[this.journeyStage()];
  }

  protected journeyStepState(step: 1 | 2 | 3 | 4): 'complete' | 'current' | 'upcoming' {
    if (this.journeyStage() === 'complete') {
      return 'complete';
    }

    const indices: Readonly<Record<SellJourneyStage, number>> = {
      prepare: 1,
      assessment: 1,
      pricing: 2,
      listing: 3,
      portfolio: 4,
      outcome: 4,
      complete: 4,
      archived: 1,
    };
    const current = indices[this.journeyStage()];

    return step < current ? 'complete' : step === current ? 'current' : 'upcoming';
  }

  private load(preserveMessages = false): void {
    const ownedProductId = this.ownedProductId();

    if (ownedProductId === null) {
      this.error.set(this.i18n.translate('ownedProduct.detail.idMissing'));
      this.loading.set(false);
      return;
    }

    this.loading.set(true);

    if (!preserveMessages) {
      this.error.set(null);
      this.success.set(null);
    }

    this.ownedProducts
      .get(ownedProductId)
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: (record) => {
          this.record.set(record);
          this.lifecycleForm.setValue({
            status: record.status,
            notes: record.notes ?? '',
          });
        },
        error: (error: unknown) => {
          this.record.set(null);
          this.error.set(
            apiErrorMessage(
              error,
              this.i18n.translate('ownedProduct.detail.loadError'),
            ),
          );
        },
      });
  }

  private ownedProductId(): string | null {
    return this.route.snapshot.paramMap.get('id');
  }

  private nullIfBlank(value: string): string | null {
    const trimmed = value.trim();

    return trimmed === '' ? null : trimmed;
  }

  private resetJourneyPanelStates(): void {
    this.pricingJourneyState.set('loading');
    this.listingJourneyState.set('loading');
    this.portfolioJourneyState.set('loading');
    this.outcomeJourneyState.set('loading');
  }
}
