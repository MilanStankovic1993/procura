import { Component, computed, effect, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { finalize, forkJoin } from 'rxjs';

import { apiErrorMessage } from '../../../core/api/api-error';
import {
  BrokerRequest,
  BrokerRequestStatus,
} from '../../../core/broker-request.models';
import { BrokerRequestService } from '../../../core/broker-request.service';
import { I18nService } from '../../../core/i18n/i18n.service';
import { TranslationKey } from '../../../core/i18n/locales/en';
import { TranslatePipe } from '../../../core/i18n/translate.pipe';
import { MarketReferenceCatalog } from '../../../core/market.models';
import { MarketReferenceService } from '../../../core/market-reference.service';
import { OrganizationContextService } from '../../../core/organizations/organization-context.service';

@Component({
  selector: 'app-broker-request-list-page',
  imports: [ReactiveFormsModule, RouterLink, TranslatePipe],
  templateUrl: './broker-request-list.page.html',
  styleUrl: './broker-request-list.page.scss',
})
export class BrokerRequestListPage {
  private readonly brokerRequests = inject(BrokerRequestService);
  private readonly markets = inject(MarketReferenceService);
  private readonly organizations = inject(OrganizationContextService);
  private readonly formBuilder = inject(FormBuilder);
  private readonly i18n = inject(I18nService);
  private loadedOrganizationId: string | null = null;

  protected readonly records = signal<readonly BrokerRequest[]>([]);
  protected readonly catalog = signal<MarketReferenceCatalog | null>(null);
  protected readonly loading = signal(true);
  protected readonly loadingMore = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly nextCursor = signal<string | null>(null);
  protected readonly canManage = computed(
    () =>
      this.organizations
        .activeOrganization()
        ?.capabilities.includes('broker-requests.manage') === true,
  );
  protected readonly filters = this.formBuilder.nonNullable.group({
    q: [''],
    status: ['' as BrokerRequestStatus | ''],
  });
  protected readonly statuses: readonly BrokerRequestStatus[] = [
    'draft',
    'submitted',
    'reviewing',
    'searching',
    'offers_available',
    'accepted',
    'completed',
    'cancelled',
  ];

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

  protected loadMore(): void {
    if (this.nextCursor() !== null && !this.loadingMore()) {
      this.load(false);
    }
  }

  protected statusLabel(status: BrokerRequestStatus): string {
    return this.i18n.translate(
      `brokerRequest.status.${status}` as TranslationKey,
    );
  }

  protected conditionLabel(condition: string): string {
    return this.i18n.translate(
      `brokerRequest.condition.${condition}` as TranslationKey,
    );
  }

  protected money(request: BrokerRequest): string {
    if (
      request.budget_max_minor === null ||
      request.budget_currency_code === null
    ) {
      return this.i18n.translate('common.notSet');
    }

    const currency = this.catalog()?.currencies.find(
      (item) => item.code === request.budget_currency_code,
    );

    return this.i18n.formatMoney(
      request.budget_max_minor,
      request.budget_currency_code,
      currency?.minor_unit ?? 2,
    );
  }

  protected countries(codes: readonly string[]): string {
    return codes
      .map((code) => this.i18n.regionName(code, code))
      .join(', ');
  }

  protected date(value: string | null): string {
    return value === null
      ? this.i18n.translate('common.notSet')
      : this.i18n.formatDate(value, { dateStyle: 'medium' });
  }

  private load(reset: boolean): void {
    const cursor = reset ? undefined : (this.nextCursor() ?? undefined);
    if (reset) {
      this.loading.set(true);
    } else {
      this.loadingMore.set(true);
    }
    this.error.set(null);
    const value = this.filters.getRawValue();

    forkJoin({
      page: this.brokerRequests.index({
        q: value.q.trim() || undefined,
        status: value.status,
        cursor,
        per_page: 20,
      }),
      catalog: this.markets.catalog(),
    })
      .pipe(
        finalize(() => {
          this.loading.set(false);
          this.loadingMore.set(false);
        }),
      )
      .subscribe({
        next: ({ page, catalog }) => {
          this.records.set(
            reset ? page.data : [...this.records(), ...page.data],
          );
          this.nextCursor.set(page.meta.next_cursor);
          this.catalog.set(catalog);
        },
        error: (error: unknown) =>
          this.error.set(
            apiErrorMessage(
              error,
              this.i18n.translate('brokerRequest.list.loadError'),
            ),
          ),
      });
  }
}
