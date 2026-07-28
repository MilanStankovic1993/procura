import { Component, computed, effect, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { finalize, forkJoin } from 'rxjs';

import { apiErrorMessage } from '../../../core/api/api-error';
import { I18nService } from '../../../core/i18n/i18n.service';
import { TranslatePipe } from '../../../core/i18n/translate.pipe';
import { MarketCurrency } from '../../../core/market.models';
import { MarketReferenceService } from '../../../core/market-reference.service';
import {
  SavedSearch,
  SavedSearchInput,
  SavedSearchMatch,
  SavedSearchMatchStatus,
} from '../../../core/monitoring.models';
import {
  monitoringIdempotencyKey,
  MonitoringService,
} from '../../../core/monitoring.service';
import { OrganizationContextService } from '../../../core/organizations/organization-context.service';

@Component({
  selector: 'app-saved-search-detail-page',
  imports: [ReactiveFormsModule, RouterLink, TranslatePipe],
  templateUrl: './saved-search-detail.page.html',
  styleUrl: './saved-search-detail.page.scss',
})
export class SavedSearchDetailPage {
  private readonly monitoring = inject(MonitoringService);
  private readonly markets = inject(MarketReferenceService);
  private readonly organizations = inject(OrganizationContextService);
  private readonly formBuilder = inject(FormBuilder);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly i18n = inject(I18nService);
  private readonly savedSearchId = this.route.snapshot.paramMap.get('id') ?? '';
  private loadedOrganizationId: string | null = null;

  protected readonly record = signal<SavedSearch | null>(null);
  protected readonly matches = signal<readonly SavedSearchMatch[]>([]);
  protected readonly currencies = signal<readonly MarketCurrency[]>([]);
  protected readonly loading = signal(true);
  protected readonly loadingMore = signal(false);
  protected readonly mutating = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly actionError = signal<string | null>(null);
  protected readonly nextCursor = signal<string | null>(null);
  protected readonly archiveArmed = signal(false);
  protected readonly canManage = computed(
    () =>
      this.organizations
        .activeOrganization()
        ?.capabilities.includes('saved-searches.manage') === true,
  );
  protected readonly matchFilters = this.formBuilder.nonNullable.group({
    status: ['' as SavedSearchMatchStatus | ''],
  });

  constructor() {
    effect(() => {
      const organizationId = this.organizations.activeOrganization()?.id ?? null;

      if (organizationId !== null && organizationId !== this.loadedOrganizationId) {
        this.loadedOrganizationId = organizationId;
        this.load();
      }
    });
  }

  protected applyMatchFilter(): void {
    this.loadMatches(true);
  }

  protected loadMoreMatches(): void {
    if (this.nextCursor() !== null && !this.loadingMore()) {
      this.loadMatches(false);
    }
  }

  protected toggleActive(): void {
    const record = this.record();

    if (record === null || record.archived || !this.canManage()) {
      return;
    }

    const input: SavedSearchInput = {
      ...record.current_version.criteria,
      title: record.title,
      active: !record.active,
      notification_channels: record.current_version.notification_channels,
      reason_code: record.active
        ? 'saved_search_paused'
        : 'saved_search_resumed',
      idempotency_key: monitoringIdempotencyKey(),
      expected_current_version_id: record.current_version_id,
    };

    this.mutate(
      this.monitoring.updateSavedSearch(record.id, input),
      'savedSearchDetail.updateError',
    );
  }

  protected armArchive(): void {
    this.archiveArmed.set(true);
  }

  protected cancelArchive(): void {
    this.archiveArmed.set(false);
  }

  protected archive(): void {
    const record = this.record();

    if (record === null || record.archived || !this.canManage()) {
      return;
    }

    this.mutating.set(true);
    this.actionError.set(null);
    this.monitoring
      .archiveSavedSearch(
        record.id,
        record.current_version_id,
        monitoringIdempotencyKey(),
      )
      .pipe(finalize(() => this.mutating.set(false)))
      .subscribe({
        next: () => {
          void this.router.navigate(['/app/saved-searches']);
        },
        error: (error: unknown) => {
          this.actionError.set(
            apiErrorMessage(
              error,
              this.i18n.translate('savedSearchDetail.archiveError'),
            ),
          );
        },
      });
  }

  protected state(search: SavedSearch): string {
    if (search.archived) {
      return this.i18n.translate('savedSearch.state.archived');
    }

    return this.i18n.translate(
      search.active ? 'savedSearch.state.active' : 'savedSearch.state.paused',
    );
  }

  protected matchState(status: SavedSearchMatchStatus): string {
    const keys = {
      matched: 'savedSearch.match.matched',
      not_matched: 'savedSearch.match.notMatched',
      insufficient_evidence: 'savedSearch.match.insufficient',
    } as const;

    return this.i18n.translate(keys[status]);
  }

  protected money(
    amount: number | null,
    currencyCode: string | null,
  ): string {
    const minorUnit = this.currencies().find(
      (currency) => currency.code === currencyCode,
    )?.minor_unit;

    return this.i18n.formatMoney(
      amount,
      currencyCode,
      minorUnit ?? null,
    );
  }

  protected percent(basisPoints: number | null): string {
    return basisPoints === null
      ? this.i18n.translate('savedSearch.notConfigured')
      : this.i18n.formatNumber(basisPoints / 100, {
          maximumFractionDigits: 2,
        }) + '%';
  }

  protected date(value: string | null): string {
    return value === null
      ? this.i18n.translate('common.unknownDate')
      : this.i18n.formatDate(value, {
          dateStyle: 'medium',
          timeStyle: 'short',
        });
  }

  protected market(search: SavedSearch): string {
    const criteria = search.current_version.criteria;
    const countryCodes = criteria.country_codes.join(', ');

    if (countryCodes !== '') {
      return countryCodes;
    }

    if (criteria.continent_code !== null) {
      return this.i18n.continentName(
        criteria.continent_code,
        criteria.continent_code,
      );
    }

    return this.i18n.translate('savedSearch.anyMarket');
  }

  protected load(): void {
    this.loading.set(true);
    this.error.set(null);
    forkJoin({
      record: this.monitoring.savedSearch(this.savedSearchId),
      matches: this.monitoring.matches(this.savedSearchId, { per_page: 20 }),
      catalog: this.markets.catalog(),
    })
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: ({ record, matches, catalog }) => {
          this.record.set(record);
          this.matches.set(matches.data);
          this.nextCursor.set(matches.meta.next_cursor);
          this.currencies.set(catalog.currencies);
        },
        error: (error: unknown) => {
          this.error.set(
            apiErrorMessage(
              error,
              this.i18n.translate('savedSearchDetail.loadError'),
            ),
          );
        },
      });
  }

  private loadMatches(reset: boolean): void {
    if (reset) {
      this.matches.set([]);
      this.nextCursor.set(null);
    }

    this.loadingMore.set(true);
    const status = this.matchFilters.controls.status.value;
    this.monitoring
      .matches(this.savedSearchId, {
        status,
        cursor: reset ? undefined : (this.nextCursor() ?? undefined),
        per_page: 20,
      })
      .pipe(finalize(() => this.loadingMore.set(false)))
      .subscribe({
        next: (page) => {
          this.matches.update((current) =>
            reset ? page.data : [...current, ...page.data],
          );
          this.nextCursor.set(page.meta.next_cursor);
        },
        error: (error: unknown) => {
          this.actionError.set(
            apiErrorMessage(
              error,
              this.i18n.translate('savedSearchDetail.matchesError'),
            ),
          );
        },
      });
  }

  private mutate(
    request: ReturnType<MonitoringService['updateSavedSearch']>,
    fallbackKey: 'savedSearchDetail.updateError',
  ): void {
    this.mutating.set(true);
    this.actionError.set(null);
    request.pipe(finalize(() => this.mutating.set(false))).subscribe({
      next: (record) => {
        this.record.set(record);
        this.archiveArmed.set(false);
      },
      error: (error: unknown) => {
        this.actionError.set(
          apiErrorMessage(error, this.i18n.translate(fallbackKey)),
        );
      },
    });
  }
}
