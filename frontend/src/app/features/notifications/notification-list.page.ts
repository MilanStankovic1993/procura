import { Component, effect, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { finalize, forkJoin } from 'rxjs';

import { apiErrorMessage } from '../../core/api/api-error';
import { I18nService } from '../../core/i18n/i18n.service';
import { TranslationKey } from '../../core/i18n/locales/en';
import { TranslatePipe } from '../../core/i18n/translate.pipe';
import { MarketCurrency } from '../../core/market.models';
import { MarketReferenceService } from '../../core/market-reference.service';
import {
  EmailDeliveryState,
  InAppNotification,
  NotificationFilterState,
  TelegramConnectionLink,
  TelegramConnectionState,
} from '../../core/monitoring.models';
import {
  monitoringIdempotencyKey,
  MonitoringService,
} from '../../core/monitoring.service';
import { OrganizationContextService } from '../../core/organizations/organization-context.service';

@Component({
  selector: 'app-notification-list-page',
  imports: [ReactiveFormsModule, RouterLink, TranslatePipe],
  templateUrl: './notification-list.page.html',
  styleUrl: './notification-list.page.scss',
})
export class NotificationListPage {
  private readonly monitoring = inject(MonitoringService);
  private readonly markets = inject(MarketReferenceService);
  private readonly organizations = inject(OrganizationContextService);
  private readonly formBuilder = inject(FormBuilder);
  private readonly i18n = inject(I18nService);
  private loadedOrganizationId: string | null = null;

  protected readonly records = signal<readonly InAppNotification[]>([]);
  protected readonly currencies = signal<readonly MarketCurrency[]>([]);
  protected readonly unreadCount = signal(0);
  protected readonly loading = signal(true);
  protected readonly loadingMore = signal(false);
  protected readonly busyId = signal<string | null>(null);
  protected readonly error = signal<string | null>(null);
  protected readonly telegramError = signal<string | null>(null);
  protected readonly telegramConnection =
    signal<TelegramConnectionState | null>(null);
  protected readonly telegramLink =
    signal<TelegramConnectionLink | null>(null);
  protected readonly telegramBusy = signal(false);
  protected readonly nextCursor = signal<string | null>(null);
  protected readonly filters = this.formBuilder.nonNullable.group({
    state: ['all' as NotificationFilterState],
  });

  constructor() {
    effect(() => {
      const organizationId = this.organizations.activeOrganization()?.id ?? null;

      if (organizationId !== null && organizationId !== this.loadedOrganizationId) {
        this.loadedOrganizationId = organizationId;
        this.load(true);
        this.loadTelegramConnection();
      }
    });
  }

  protected applyFilter(): void {
    this.load(true);
  }

  protected retry(): void {
    this.load(true);
    this.loadTelegramConnection();
  }

  protected beginTelegramConnection(): void {
    if (this.telegramBusy()) {
      return;
    }

    this.telegramBusy.set(true);
    this.telegramError.set(null);
    this.monitoring
      .beginTelegramConnection()
      .pipe(finalize(() => this.telegramBusy.set(false)))
      .subscribe({
        next: (link) => {
          this.telegramLink.set(link);
          this.loadTelegramConnection();
        },
        error: (error: unknown) => {
          this.telegramError.set(
            apiErrorMessage(
              error,
              this.i18n.translate('notificationList.telegramConnectError'),
            ),
          );
        },
      });
  }

  protected revokeTelegramConnection(): void {
    if (this.telegramBusy()) {
      return;
    }

    this.telegramBusy.set(true);
    this.telegramError.set(null);
    this.monitoring
      .revokeTelegramConnection()
      .pipe(finalize(() => this.telegramBusy.set(false)))
      .subscribe({
        next: () => {
          this.telegramLink.set(null);
          this.loadTelegramConnection();
        },
        error: (error: unknown) => {
          this.telegramError.set(
            apiErrorMessage(
              error,
              this.i18n.translate('notificationList.telegramRevokeError'),
            ),
          );
        },
      });
  }

  protected refreshTelegramConnection(): void {
    this.loadTelegramConnection();
  }

  protected loadMore(): void {
    if (this.nextCursor() !== null && !this.loadingMore()) {
      this.load(false);
    }
  }

  protected markRead(notification: InAppNotification): void {
    this.changeState(notification, 'read');
  }

  protected markUnread(notification: InAppNotification): void {
    this.changeState(notification, 'unread');
  }

  protected archive(notification: InAppNotification): void {
    this.changeState(notification, 'archived');
  }

  protected money(notification: InAppNotification): string {
    const currency = this.currencies().find(
      (candidate) => candidate.code === notification.listing.currency_code,
    );

    return this.i18n.formatMoney(
      notification.listing.asking_price_minor,
      notification.listing.currency_code,
      currency?.minor_unit ?? null,
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

  protected emailState(
    notification: InAppNotification,
  ): TranslationKey | null {
    const state = notification.delivery.email?.state;

    if (state === undefined) {
      return null;
    }

    const keys: Readonly<Record<EmailDeliveryState, TranslationKey>> = {
      queued: 'notificationList.emailQueued',
      attempting: 'notificationList.emailAttempting',
      delivered: 'notificationList.emailDelivered',
      failed: 'notificationList.emailFailed',
      exhausted: 'notificationList.emailExhausted',
      suppressed: 'notificationList.emailSuppressed',
    };

    return keys[state];
  }

  protected telegramState(
    notification: InAppNotification,
  ): TranslationKey | null {
    const state = notification.delivery.telegram?.state;

    if (state === undefined) {
      return null;
    }

    const keys: Readonly<Record<EmailDeliveryState, TranslationKey>> = {
      queued: 'notificationList.telegramQueued',
      attempting: 'notificationList.telegramAttempting',
      delivered: 'notificationList.telegramDelivered',
      failed: 'notificationList.telegramFailed',
      exhausted: 'notificationList.telegramExhausted',
      suppressed: 'notificationList.telegramSuppressed',
    };

    return keys[state];
  }

  private changeState(
    notification: InAppNotification,
    state: 'read' | 'unread' | 'archived',
  ): void {
    if (this.busyId() !== null) {
      return;
    }

    this.busyId.set(notification.id);
    this.error.set(null);
    this.monitoring
      .recordNotificationState(
        notification.id,
        state,
        notification.current_log_id,
        monitoringIdempotencyKey(),
      )
      .pipe(finalize(() => this.busyId.set(null)))
      .subscribe({
        next: () => this.load(true),
        error: (error: unknown) => {
          this.error.set(
            apiErrorMessage(
              error,
              this.i18n.translate('notificationList.stateError'),
            ),
          );
        },
      });
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
    const pageRequest = this.monitoring.notifications(
      this.filters.controls.state.value,
      reset ? undefined : (this.nextCursor() ?? undefined),
    );
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
          this.unreadCount.set(result.page.meta.unread_count);

          this.currencies.set(result.catalog.currencies);
        },
        error: (error: unknown) => {
          this.error.set(
            apiErrorMessage(
              error,
              this.i18n.translate('notificationList.loadError'),
            ),
          );
        },
      });
  }

  private loadTelegramConnection(): void {
    this.telegramError.set(null);
    this.monitoring.telegramConnection().subscribe({
      next: (connection) => this.telegramConnection.set(connection),
      error: (error: unknown) => {
        this.telegramError.set(
          apiErrorMessage(
            error,
            this.i18n.translate('notificationList.telegramLoadError'),
          ),
        );
      },
    });
  }
}
