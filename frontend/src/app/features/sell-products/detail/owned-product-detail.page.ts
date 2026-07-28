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
  protected readonly canManage = computed(
    () =>
      this.organizations
        .activeOrganization()
        ?.capabilities.includes('owned-products.manage') === true,
  );
  protected readonly canMutate = computed(
    () => this.canManage() && this.record()?.status !== 'archived',
  );
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
}
