import { Component, computed, effect, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { finalize } from 'rxjs';

import { apiErrorMessage } from '../../../core/api/api-error';
import { I18nService } from '../../../core/i18n/i18n.service';
import { TranslationKey } from '../../../core/i18n/locales/en';
import { TranslatePipe } from '../../../core/i18n/translate.pipe';
import {
  OwnedProduct,
  OwnedProductCondition,
  OwnedProductStatus,
} from '../../../core/owned-product.models';
import { OwnedProductService } from '../../../core/owned-product.service';
import { OrganizationContextService } from '../../../core/organizations/organization-context.service';

@Component({
  selector: 'app-owned-product-list-page',
  imports: [ReactiveFormsModule, RouterLink, TranslatePipe],
  templateUrl: './owned-product-list.page.html',
  styleUrl: './owned-product-list.page.scss',
})
export class OwnedProductListPage {
  private readonly ownedProducts = inject(OwnedProductService);
  private readonly organizations = inject(OrganizationContextService);
  private readonly formBuilder = inject(FormBuilder);
  private readonly i18n = inject(I18nService);
  private loadedOrganizationId: string | null = null;

  protected readonly records = signal<readonly OwnedProduct[]>([]);
  protected readonly loading = signal(true);
  protected readonly loadingMore = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly nextCursor = signal<string | null>(null);
  protected readonly canManage = computed(
    () =>
      this.organizations
        .activeOrganization()
        ?.capabilities.includes('owned-products.manage') === true,
  );
  protected readonly filters = this.formBuilder.nonNullable.group({
    q: [''],
    status: ['' as OwnedProductStatus | ''],
  });

  constructor() {
    effect(() => {
      const organizationId = this.organizations.activeOrganization()?.id ?? null;

      if (organizationId !== null && organizationId !== this.loadedOrganizationId) {
        this.loadedOrganizationId = organizationId;
        this.load(true);
      }
    });
  }

  protected applyFilters(): void {
    this.load(true);
  }

  protected clearFilters(): void {
    this.filters.reset({ q: '', status: '' });
    this.load(true);
  }

  protected retry(): void {
    this.load(true);
  }

  protected loadMore(): void {
    if (this.nextCursor() !== null && !this.loadingMore()) {
      this.load(false);
    }
  }

  protected title(record: OwnedProduct): string {
    const value = [record.brand_name, record.model_name].filter(Boolean).join(' ');

    return value || this.i18n.translate('ownedProduct.common.unknownProduct');
  }

  protected date(value: string | null): string {
    return value === null
      ? this.i18n.translate('common.unknownDate')
      : this.i18n.formatDate(value, { dateStyle: 'medium', timeStyle: 'short' });
  }

  protected statusLabel(status: OwnedProductStatus): string {
    const keys: Readonly<Record<OwnedProductStatus, TranslationKey>> = {
      draft: 'ownedProduct.status.draft',
      ready: 'ownedProduct.status.ready',
      archived: 'ownedProduct.status.archived',
    };

    return this.i18n.translate(keys[status]);
  }

  protected conditionLabel(condition: OwnedProductCondition): string {
    return this.i18n.translate(
      `ownedProduct.condition.${condition}` as TranslationKey,
    );
  }

  protected continent(record: OwnedProduct): string {
    return this.i18n.continentName(
      record.target_continent_code,
      record.target_continent_code,
    );
  }

  private load(reset: boolean): void {
    if (reset) {
      this.loading.set(true);
      this.records.set([]);
      this.nextCursor.set(null);
    } else {
      this.loadingMore.set(true);
    }

    this.error.set(null);
    const filters = this.filters.getRawValue();

    this.ownedProducts
      .list({
        q: filters.q.trim(),
        status: filters.status,
        cursor: reset ? undefined : (this.nextCursor() ?? undefined),
        per_page: 20,
      })
      .pipe(
        finalize(() => {
          this.loading.set(false);
          this.loadingMore.set(false);
        }),
      )
      .subscribe({
        next: (page) => {
          this.records.update((current) =>
            reset ? page.data : [...current, ...page.data],
          );
          this.nextCursor.set(page.meta.next_cursor);
        },
        error: (error: unknown) => {
          this.error.set(
            apiErrorMessage(
              error,
              this.i18n.translate('ownedProduct.list.loadError'),
            ),
          );
        },
      });
  }
}
