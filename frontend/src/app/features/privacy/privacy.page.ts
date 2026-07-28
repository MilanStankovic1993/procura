import { Component, inject, OnInit, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { finalize, forkJoin } from 'rxjs';

import { apiErrorMessage } from '../../core/api/api-error';
import { I18nService } from '../../core/i18n/i18n.service';
import { TranslationKey } from '../../core/i18n/locales/en';
import { TranslatePipe } from '../../core/i18n/translate.pipe';
import { MarketReferenceService } from '../../core/market-reference.service';
import { MarketReferenceCatalog } from '../../core/market.models';
import {
  PrivacyRequest,
  PrivacyRequestActorType,
  PrivacyRequestEvent,
  PrivacyRequestStatus,
  PrivacyRequestType,
} from '../../core/privacy.models';
import { PrivacyService } from '../../core/privacy.service';

@Component({
  selector: 'app-privacy-page',
  imports: [ReactiveFormsModule, TranslatePipe],
  templateUrl: './privacy.page.html',
  styleUrl: './privacy.page.scss',
})
export class PrivacyPage implements OnInit {
  private readonly privacy = inject(PrivacyService);
  private readonly markets = inject(MarketReferenceService);
  private readonly formBuilder = inject(FormBuilder);
  private readonly i18n = inject(I18nService);

  protected readonly requests = signal<readonly PrivacyRequest[]>([]);
  protected readonly catalog = signal<MarketReferenceCatalog | null>(null);
  protected readonly loading = signal(true);
  protected readonly submitting = signal(false);
  protected readonly cancellingId = signal<string | null>(null);
  protected readonly cancelConfirmationId = signal<string | null>(null);
  protected readonly error = signal<string | null>(null);
  protected readonly success = signal<string | null>(null);

  protected readonly form = this.formBuilder.nonNullable.group({
    type: ['data_export' as PrivacyRequestType, [Validators.required]],
    residence_country_code: [''],
    reason: ['', [Validators.maxLength(1000)]],
    privacy_notice_confirmed: [false, [Validators.requiredTrue]],
  });

  ngOnInit(): void {
    forkJoin({
      requests: this.privacy.requests(),
      catalog: this.markets.catalog(),
    })
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: ({ requests, catalog }) => {
          this.requests.set(requests);
          this.catalog.set(catalog);
        },
        error: (error: unknown) =>
          this.error.set(
            apiErrorMessage(error, this.i18n.translate('privacy.loadError')),
          ),
      });
  }

  protected submit(): void {
    if (this.form.invalid || this.submitting()) {
      this.form.markAllAsTouched();
      return;
    }

    const value = this.form.getRawValue();
    const reason = value.reason.trim();
    this.submitting.set(true);
    this.error.set(null);
    this.success.set(null);
    this.privacy
      .create({
        type: value.type,
        residence_country_code: value.residence_country_code || null,
        reason: reason === '' ? null : reason,
        privacy_notice_confirmed: true,
        idempotency_key: crypto.randomUUID(),
      })
      .pipe(finalize(() => this.submitting.set(false)))
      .subscribe({
        next: (request) => {
          this.upsert(request);
          this.form.controls.reason.setValue('');
          this.form.controls.privacy_notice_confirmed.setValue(false);
          this.success.set(this.i18n.translate('privacy.created'));
        },
        error: (error: unknown) =>
          this.error.set(
            apiErrorMessage(error, this.i18n.translate('privacy.createError')),
          ),
      });
  }

  protected askToCancel(requestId: string): void {
    this.cancelConfirmationId.set(requestId);
  }

  protected keepRequest(): void {
    this.cancelConfirmationId.set(null);
  }

  protected cancel(request: PrivacyRequest): void {
    if (!request.can_cancel || this.cancellingId() !== null) return;

    this.cancellingId.set(request.id);
    this.error.set(null);
    this.success.set(null);
    this.privacy
      .cancel(request.id, request.current_event_id, crypto.randomUUID())
      .pipe(
        finalize(() => {
          this.cancellingId.set(null);
          this.cancelConfirmationId.set(null);
        }),
      )
      .subscribe({
        next: (updated) => {
          this.upsert(updated);
          this.success.set(this.i18n.translate('privacy.cancelled'));
        },
        error: (error: unknown) =>
          this.error.set(
            apiErrorMessage(error, this.i18n.translate('privacy.cancelError')),
          ),
      });
  }

  protected typeLabel(type: PrivacyRequestType): string {
    return this.i18n.translate(`privacy.type.${type}` as TranslationKey);
  }

  protected statusLabel(status: PrivacyRequestStatus): string {
    return this.i18n.translate(`privacy.status.${status}` as TranslationKey);
  }

  protected actorLabel(actorType: PrivacyRequestActorType): string {
    return this.i18n.translate(`privacy.actor.${actorType}` as TranslationKey);
  }

  protected blockerLabel(code: string): string {
    const key = `privacy.blocker.${code}` as TranslationKey;
    const translated = this.i18n.translate(key);

    return translated === key ? code.replaceAll('_', ' ') : translated;
  }

  protected eventReason(event: PrivacyRequestEvent): string {
    const key = `privacy.event.${event.reason_code}` as TranslationKey;
    const translated = this.i18n.translate(key);

    return translated === key ? event.reason_code.replaceAll('_', ' ') : translated;
  }

  protected date(value: string): string {
    return this.i18n.formatDate(value, {
      dateStyle: 'medium',
      timeStyle: 'short',
    });
  }

  protected countryName(code: string, fallback: string): string {
    return this.i18n.regionName(code, fallback);
  }

  private upsert(request: PrivacyRequest): void {
    const existing = this.requests().filter((item) => item.id !== request.id);
    this.requests.set([request, ...existing]);
  }
}
