import { Component, computed, effect, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { finalize } from 'rxjs';

import { apiErrorMessage } from '../../../core/api/api-error';
import { I18nService } from '../../../core/i18n/i18n.service';
import { TranslationKey } from '../../../core/i18n/locales/en';
import { TranslatePipe } from '../../../core/i18n/translate.pipe';
import { Listing, ListingStatus } from '../../../core/listing.models';
import { ListingService } from '../../../core/listing.service';
import { OrganizationContextService } from '../../../core/organizations/organization-context.service';

@Component({
  selector: 'app-listing-list-page',
  imports: [ReactiveFormsModule, RouterLink, TranslatePipe],
  templateUrl: './listing-list.page.html',
  styleUrl: './listing-list.page.scss',
})
export class ListingListPage {
  private readonly listings = inject(ListingService);
  private readonly organizations = inject(OrganizationContextService);
  private readonly formBuilder = inject(FormBuilder);
  private readonly i18n = inject(I18nService);
  private loadedOrganizationId: string | null = null;

  protected readonly records = signal<readonly Listing[]>([]);
  protected readonly loading = signal(true);
  protected readonly loadingMore = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly nextCursor = signal<string | null>(null);
  protected readonly canManage = computed(
    () =>
      this.organizations.activeOrganization()?.capabilities.includes('listings.manage') ===
      true,
  );
  protected readonly filters = this.formBuilder.nonNullable.group({
    q: [''],
    status: ['' as ListingStatus | ''],
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

  protected price(listing: Listing): string {
    return this.i18n.formatMoney(
      listing.asking_price_minor,
      listing.currency_code,
      listing.currency_minor_unit,
    );
  }

  protected date(value: string | null): string {
    if (value === null) {
      return this.i18n.translate('common.unknownDate');
    }

    return this.i18n.formatDate(value, {
      dateStyle: 'medium',
      timeStyle: 'short',
    });
  }

  protected statusLabel(status: ListingStatus): string {
    const keys: Readonly<Record<ListingStatus, TranslationKey>> = {
      active: 'listing.status.active',
      reserved: 'listing.status.reserved',
      sold: 'listing.status.sold',
      removed: 'listing.status.removed',
      expired: 'listing.status.expired',
      unknown: 'listing.status.unknown',
    };

    return this.i18n.translate(keys[status]);
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

    this.listings
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
            apiErrorMessage(error, this.i18n.translate('listingList.loadError')),
          );
        },
      });
  }
}
