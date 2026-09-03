import { Component, computed, effect, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { finalize, forkJoin } from 'rxjs';

import { apiErrorMessage } from '../../../core/api/api-error';
import { I18nService } from '../../../core/i18n/i18n.service';
import { TranslatePipe } from '../../../core/i18n/translate.pipe';
import { MarketCurrency } from '../../../core/market.models';
import {
  SavedSearch,
  SavedSearchState,
} from '../../../core/monitoring.models';
import { MonitoringService } from '../../../core/monitoring.service';
import { MarketReferenceService } from '../../../core/market-reference.service';
import { OrganizationContextService } from '../../../core/organizations/organization-context.service';

@Component({
  selector: 'app-saved-search-list-page',
  imports: [ReactiveFormsModule, RouterLink, TranslatePipe],
  templateUrl: './saved-search-list.page.html',
  styleUrl: './saved-search-list.page.scss',
})
export class SavedSearchListPage {
  private readonly monitoring = inject(MonitoringService);
  private readonly markets = inject(MarketReferenceService);
  private readonly organizations = inject(OrganizationContextService);
  private readonly formBuilder = inject(FormBuilder);
  private readonly i18n = inject(I18nService);
  private loadedOrganizationId: string | null = null;

  protected readonly records = signal<readonly SavedSearch[]>([]);
  protected readonly currencies = signal<readonly MarketCurrency[]>([]);
  protected readonly loading = signal(true);
  protected readonly loadingMore = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly nextCursor = signal<string | null>(null);
  protected readonly canManage = computed(
    () =>
      this.organizations
        .activeOrganization()
        ?.capabilities.includes('saved-searches.manage') === true,
  );
  protected readonly filters = this.formBuilder.nonNullable.group({
    q: [''],
    state: ['active' as SavedSearchState],
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
    this.filters.reset({ q: '', state: 'active' });
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

  protected state(search: SavedSearch): string {
    if (search.archived) {
      return this.i18n.translate('savedSearch.state.archived');
    }

    return this.i18n.translate(
      search.active ? 'savedSearch.state.active' : 'savedSearch.state.paused',
    );
  }

  protected priceRange(search: SavedSearch): string {
    const criteria = search.current_version.criteria;
    const currency = this.currencies().find(
      (candidate) => candidate.code === criteria.price_currency_code,
    );

    if (
      criteria.price_currency_code === null ||
      (criteria.minimum_price_minor === null &&
        criteria.maximum_price_minor === null)
    ) {
      return this.i18n.translate('savedSearch.anyPrice');
    }

    const minimum = this.i18n.formatMoney(
      criteria.minimum_price_minor,
      criteria.price_currency_code,
      currency?.minor_unit ?? null,
    );
    const maximum = this.i18n.formatMoney(
      criteria.maximum_price_minor,
      criteria.price_currency_code,
      currency?.minor_unit ?? null,
    );

    if (criteria.minimum_price_minor === null) {
      return this.i18n.translate('savedSearch.upToPrice', { price: maximum });
    }

    if (criteria.maximum_price_minor === null) {
      return this.i18n.translate('savedSearch.fromPrice', { price: minimum });
    }

    return this.i18n.translate('savedSearch.priceRange', {
      minimum,
      maximum,
    });
  }

  protected market(search: SavedSearch): string {
    const criteria = search.current_version.criteria;
    const countries = criteria.country_codes.join(', ');

    if (countries !== '') {
      return countries;
    }

    if (criteria.continent_code !== null) {
      return this.i18n.continentName(
        criteria.continent_code,
        criteria.continent_code,
      );
    }

    return this.i18n.translate('savedSearch.anyMarket');
  }

  protected date(value: string | null): string {
    return value === null
      ? this.i18n.translate('common.unknownDate')
      : this.i18n.formatDate(value, { dateStyle: 'medium' });
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
    const values = this.filters.getRawValue();
    const pageRequest = this.monitoring.savedSearches({
      q: values.q.trim(),
      state: values.state,
      cursor: reset ? undefined : (this.nextCursor() ?? undefined),
      per_page: 20,
    });
    const request = forkJoin({
      page: pageRequest,
      catalog: this.markets.catalog(),
    });

    request
      .pipe(
        finalize(() => {
          this.loading.set(false);
          this.loadingMore.set(false);
        }),
      )
      .subscribe({
        next: (result) => {
          this.records.update((current) =>
            reset ? result.page.data : [...current, ...result.page.data],
          );
          this.nextCursor.set(result.page.meta.next_cursor);

          this.currencies.set(result.catalog.currencies);
        },
        error: (error: unknown) => {
          this.error.set(
            apiErrorMessage(
              error,
              this.i18n.translate('savedSearchList.loadError'),
            ),
          );
        },
      });
  }
}
