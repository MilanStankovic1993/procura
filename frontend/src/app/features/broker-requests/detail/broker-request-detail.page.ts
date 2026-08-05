import { Component, computed, effect, inject, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { finalize, forkJoin, Observable } from 'rxjs';

import { apiErrorMessage } from '../../../core/api/api-error';
import {
  BrokerRequest,
  BrokerCommissionStatus,
  BrokerPaymentCase,
  BrokerPaymentCaseEvent,
  BrokerPaymentCaseOutcome,
  BrokerPaymentCaseStatus,
  BrokerPaymentCaseType,
  BrokerReport,
  BrokerRequestEvent,
  BrokerRequestOffer,
  BrokerRequestOfferStatus,
  BrokerRequestStatus,
  BrokerTransactionEvent,
  BrokerTransactionStatus,
} from '../../../core/broker-request.models';
import {
  brokerRequestIdempotencyKey,
  BrokerRequestService,
} from '../../../core/broker-request.service';
import { I18nService } from '../../../core/i18n/i18n.service';
import { TranslationKey } from '../../../core/i18n/locales/en';
import { TranslatePipe } from '../../../core/i18n/translate.pipe';
import { MarketReferenceCatalog } from '../../../core/market.models';
import { MarketReferenceService } from '../../../core/market-reference.service';
import { OrganizationContextService } from '../../../core/organizations/organization-context.service';

@Component({
  selector: 'app-broker-request-detail-page',
  imports: [RouterLink, TranslatePipe],
  templateUrl: './broker-request-detail.page.html',
  styleUrl: './broker-request-detail.page.scss',
})
export class BrokerRequestDetailPage {
  private readonly brokerRequests = inject(BrokerRequestService);
  private readonly markets = inject(MarketReferenceService);
  private readonly organizations = inject(OrganizationContextService);
  private readonly route = inject(ActivatedRoute);
  private readonly i18n = inject(I18nService);
  private readonly requestId = this.route.snapshot.paramMap.get('id') ?? '';
  private loadedOrganizationId: string | null = null;

  protected readonly record = signal<BrokerRequest | null>(null);
  protected readonly catalog = signal<MarketReferenceCatalog | null>(null);
  protected readonly loading = signal(true);
  protected readonly mutating = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly success = signal<string | null>(null);
  protected readonly cancelArmed = signal(false);
  protected readonly acceptanceArmedOfferId = signal<string | null>(null);
  protected readonly canManage = computed(
    () =>
      this.organizations
        .activeOrganization()
        ?.capabilities.includes('broker-requests.manage') === true,
  );
  protected readonly canCancel = computed(() => {
    const status = this.record()?.status;

    return (
      this.canManage() &&
      status !== undefined &&
      ['draft', 'submitted', 'reviewing', 'searching', 'offers_available'].includes(
        status,
      )
    );
  });
  protected readonly hasMultipleOfferCurrencies = computed(
    () =>
      new Set(
        (this.record()?.offers ?? []).map((offer) => offer.currency_code),
      ).size > 1,
  );

  constructor() {
    effect(() => {
      const organizationId = this.organizations.activeOrganization()?.id ?? null;

      if (organizationId !== null && organizationId !== this.loadedOrganizationId) {
        this.loadedOrganizationId = organizationId;
        this.load();
      }
    });
  }

  protected submit(): void {
    const record = this.record();

    if (record === null || record.status !== 'draft' || !this.canManage()) {
      return;
    }

    this.mutate(
      this.brokerRequests.submit(
        record.id,
        record.current_event_id,
        brokerRequestIdempotencyKey(),
      ),
      'brokerRequest.detail.submitted',
      'brokerRequest.detail.submitError',
    );
  }

  protected armCancel(): void {
    this.cancelArmed.set(true);
  }

  protected keepRequest(): void {
    this.cancelArmed.set(false);
  }

  protected cancel(): void {
    const record = this.record();

    if (record === null || !this.canCancel()) {
      return;
    }

    this.mutate(
      this.brokerRequests.cancel(
        record.id,
        record.current_event_id,
        brokerRequestIdempotencyKey(),
      ),
      'brokerRequest.detail.cancelled',
      'brokerRequest.detail.cancelError',
    );
  }

  protected canAcceptOffer(offer: BrokerRequestOffer): boolean {
    return (
      this.canManage() &&
      this.record()?.status === 'offers_available' &&
      offer.status === 'presented' &&
      offer.can_accept &&
      !offer.is_expired
    );
  }

  protected armOfferAcceptance(offerId: string): void {
    this.acceptanceArmedOfferId.set(offerId);
  }

  protected keepComparing(): void {
    this.acceptanceArmedOfferId.set(null);
  }

  protected acceptOffer(offer: BrokerRequestOffer): void {
    const record = this.record();

    if (
      record === null ||
      this.acceptanceArmedOfferId() !== offer.id ||
      !this.canAcceptOffer(offer)
    ) {
      return;
    }

    this.mutate(
      this.brokerRequests.acceptOffer(
        record.id,
        offer.id,
        record.current_event_id,
        offer.current_event_id,
        brokerRequestIdempotencyKey(),
      ),
      'brokerRequest.offer.acceptedSuccess',
      'brokerRequest.offer.acceptError',
    );
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

  protected offerStatusLabel(status: BrokerRequestOfferStatus): string {
    return this.i18n.translate(
      `brokerRequest.offer.status.${status}` as TranslationKey,
    );
  }

  protected transactionStatusLabel(status: BrokerTransactionStatus): string {
    return this.i18n.translate(
      `brokerRequest.transaction.status.${status}` as TranslationKey,
    );
  }

  protected commissionStatusLabel(status: BrokerCommissionStatus): string {
    return this.i18n.translate(
      `brokerRequest.commission.status.${status}` as TranslationKey,
    );
  }

  protected transactionEventLabel(event: BrokerTransactionEvent): string {
    return this.transactionStatusLabel(event.to_status);
  }

  protected commissionRate(basisPoints: number): string {
    return `${this.i18n.formatNumber(basisPoints / 100, {
      minimumFractionDigits: 0,
      maximumFractionDigits: 2,
    })}%`;
  }

  protected reportStatusLabel(report: BrokerReport): string {
    return this.i18n.translate(
      `brokerRequest.report.status.${report.status}` as TranslationKey,
    );
  }

  protected reportLocale(report: BrokerReport): string {
    return this.i18n.translate(`language.${report.locale}` as TranslationKey);
  }

  protected reportSize(bytes: number): string {
    return `${this.i18n.formatNumber(bytes / 1024, {
      minimumFractionDigits: 1,
      maximumFractionDigits: 1,
    })} KiB`;
  }

  protected paymentCaseTypeLabel(type: BrokerPaymentCaseType): string {
    return this.i18n.translate(
      `brokerRequest.paymentCase.type.${type}` as TranslationKey,
    );
  }

  protected paymentCaseStatusLabel(status: BrokerPaymentCaseStatus): string {
    return this.i18n.translate(
      `brokerRequest.paymentCase.status.${status}` as TranslationKey,
    );
  }

  protected paymentCaseOutcomeLabel(
    outcome: BrokerPaymentCaseOutcome,
  ): string {
    return this.i18n.translate(
      `brokerRequest.paymentCase.outcome.${outcome}` as TranslationKey,
    );
  }

  protected paymentCaseEventLabel(event: BrokerPaymentCaseEvent): string {
    return event.resolution_outcome === null
      ? this.paymentCaseStatusLabel(event.to_status)
      : this.paymentCaseOutcomeLabel(event.resolution_outcome);
  }

  protected paymentCaseAmount(
    paymentCase: BrokerPaymentCase,
    resolved = false,
  ): string {
    const amount = resolved
      ? paymentCase.resolved_amount_minor
      : paymentCase.requested_amount_minor;

    return amount === null
      ? this.i18n.translate('common.notSet')
      : this.offerMoney(amount, paymentCase.currency_code);
  }

  protected eventLabel(event: BrokerRequestEvent): string {
    const key = `brokerRequest.event.${event.reason_code}` as TranslationKey;
    const translated = this.i18n.translate(key);

    return translated === key
      ? event.reason_code.replaceAll('_', ' ')
      : translated;
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

  protected offerMoney(amountMinor: number, currencyCode: string): string {
    const currency = this.catalog()?.currencies.find(
      (item) => item.code === currencyCode,
    );

    return this.i18n.formatMoney(
      amountMinor,
      currencyCode,
      currency?.minor_unit ?? 2,
    );
  }

  protected countries(codes: readonly string[]): string {
    return codes
      .map((code) => this.i18n.regionName(code, code))
      .join(', ');
  }

  protected date(value: string | null, includeTime = false): string {
    return value === null
      ? this.i18n.translate('common.notSet')
      : this.i18n.formatDate(
          value,
          includeTime
            ? { dateStyle: 'medium', timeStyle: 'short' }
            : { dateStyle: 'medium' },
        );
  }

  private load(): void {
    this.loading.set(true);
    this.error.set(null);
    forkJoin({
      request: this.brokerRequests.show(this.requestId),
      catalog: this.markets.catalog(),
    })
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: ({ request, catalog }) => {
          this.record.set(request);
          this.catalog.set(catalog);
        },
        error: (error: unknown) =>
          this.error.set(
            apiErrorMessage(
              error,
              this.i18n.translate('brokerRequest.detail.loadError'),
            ),
          ),
      });
  }

  private mutate(
    operation: Observable<BrokerRequest>,
    successKey: TranslationKey,
    errorKey: TranslationKey,
  ): void {
    this.mutating.set(true);
    this.error.set(null);
    this.success.set(null);
    operation.pipe(finalize(() => this.mutating.set(false))).subscribe({
      next: (request) => {
        this.record.set(request);
        this.cancelArmed.set(false);
        this.acceptanceArmedOfferId.set(null);
        this.success.set(this.i18n.translate(successKey));
      },
      error: (error: unknown) =>
        this.error.set(
          apiErrorMessage(error, this.i18n.translate(errorKey)),
        ),
    });
  }
}
