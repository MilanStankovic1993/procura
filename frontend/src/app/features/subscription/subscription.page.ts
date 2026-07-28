import { Component, effect, inject, signal } from '@angular/core';
import { finalize } from 'rxjs';

import { I18nService } from '../../core/i18n/i18n.service';
import { TranslationKey } from '../../core/i18n/locales/en';
import { TranslatePipe } from '../../core/i18n/translate.pipe';
import { OrganizationContextService } from '../../core/organizations/organization-context.service';
import {
  BillingInterval,
  BillingOffer,
  OrganizationSubscription,
  SubscriptionFeature,
  SubscriptionPlan,
} from '../../core/subscription.models';
import { SubscriptionService } from '../../core/subscription.service';

@Component({
  selector: 'app-subscription-page',
  imports: [TranslatePipe],
  templateUrl: './subscription.page.html',
  styleUrl: './subscription.page.scss',
})
export class SubscriptionPage {
  private readonly subscriptions = inject(SubscriptionService);
  private readonly organizations = inject(OrganizationContextService);
  private readonly i18n = inject(I18nService);
  private loadedOrganizationId: string | null = null;

  protected readonly subscription = signal<OrganizationSubscription | null>(null);
  protected readonly loading = signal(true);
  protected readonly error = signal<string | null>(null);
  protected readonly billingError = signal<string | null>(null);
  protected readonly processingBillingAction = signal<string | null>(null);

  constructor() {
    effect(() => {
      const organizationId = this.organizations.activeOrganization()?.id ?? null;
      if (organizationId !== null && organizationId !== this.loadedOrganizationId) {
        this.loadedOrganizationId = organizationId;
        this.load();
      }
    });
  }

  protected label(feature: SubscriptionFeature): string {
    const labels: Readonly<Record<string, TranslationKey>> = {
      'analyses.monthly': 'subscription.feature.analysesMonthly',
      'saved_searches.total': 'subscription.feature.savedSearches',
      'team_members.total': 'subscription.feature.teamMembers',
      'notifications.email': 'subscription.feature.emailNotifications',
      'notifications.telegram': 'subscription.feature.telegramNotifications',
      'price_history.basic': 'subscription.feature.basicPriceHistory',
      'reports.full_risk': 'subscription.feature.fullRiskReport',
      'analysis.priority': 'subscription.feature.priorityAnalysis',
      profit_tracking: 'subscription.feature.profitTracking',
      'organization.reporting': 'subscription.feature.organizationReporting',
      'broker_requests.monthly': 'subscription.feature.brokerRequestsMonthly',
      'exports.monthly': 'subscription.feature.exportsMonthly',
      'support.priority': 'subscription.feature.prioritySupport',
    };
    const key = labels[feature.code];

    return key === undefined ? feature.code : this.i18n.translate(key);
  }

  protected planName(plan: SubscriptionPlan): string {
    return this.i18n.translate(`subscription.plan.${plan.code}.name`);
  }

  protected planDescription(plan: SubscriptionPlan): string {
    return this.i18n.translate(`subscription.plan.${plan.code}.description`);
  }

  protected date(value: string): string {
    return this.i18n.formatDate(value, { dateStyle: 'medium' });
  }

  protected percent(feature: SubscriptionFeature): number {
    if (feature.limit === null || feature.used === null || feature.limit === 0) return 0;
    return Math.min(100, Math.round((feature.used / feature.limit) * 100));
  }

  protected offerPlanName(offer: BillingOffer): string {
    return this.i18n.translate(`subscription.plan.${offer.plan}.name`);
  }

  protected interval(interval: BillingInterval): string {
    return this.i18n.translate(`subscription.billing.interval.${interval}`);
  }

  protected offerPrice(offer: BillingOffer): string {
    if (offer.amount_minor <= 0) {
      return this.i18n.translate('subscription.billing.pricePending');
    }

    return this.i18n.formatMoney(
      offer.amount_minor,
      offer.currency.toUpperCase(),
      offer.currency_minor_unit,
    );
  }

  protected billingStatus(status: string | null): string {
    const labels: Readonly<Record<string, TranslationKey>> = {
      active: 'subscription.billing.status.active',
      trialing: 'subscription.billing.status.trialing',
      past_due: 'subscription.billing.status.pastDue',
      incomplete: 'subscription.billing.status.incomplete',
      unpaid: 'subscription.billing.status.unpaid',
      canceled: 'subscription.billing.status.canceled',
    };

    return status === null
      ? this.i18n.translate('subscription.billing.status.none')
      : this.i18n.translate(labels[status] ?? 'subscription.billing.status.attention');
  }

  protected startCheckout(offer: BillingOffer): void {
    if (!offer.available || this.processingBillingAction() !== null) return;

    const action = `${offer.plan}:${offer.interval}`;
    this.processingBillingAction.set(action);
    this.billingError.set(null);
    this.subscriptions
      .checkout(offer.plan, offer.interval, crypto.randomUUID())
      .pipe(finalize(() => this.processingBillingAction.set(null)))
      .subscribe({
        next: ({ url }) => window.location.assign(url),
        error: () =>
          this.billingError.set(
            this.i18n.translate('subscription.billing.actionError'),
          ),
      });
  }

  protected openPortal(): void {
    if (this.processingBillingAction() !== null) return;

    this.processingBillingAction.set('portal');
    this.billingError.set(null);
    this.subscriptions
      .portal()
      .pipe(finalize(() => this.processingBillingAction.set(null)))
      .subscribe({
        next: ({ url }) => window.location.assign(url),
        error: () =>
          this.billingError.set(
            this.i18n.translate('subscription.billing.actionError'),
          ),
      });
  }

  protected retry(): void {
    this.load();
  }

  private load(): void {
    this.loading.set(true);
    this.error.set(null);
    this.subscriptions
      .current()
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: (subscription) => this.subscription.set(subscription),
        error: () => {
          this.subscription.set(null);
          this.error.set(this.i18n.translate('subscription.loadError'));
        },
      });
  }
}
