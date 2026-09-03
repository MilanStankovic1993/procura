import { Component, computed, inject, OnInit, signal } from '@angular/core';
import {
  FormBuilder,
  ReactiveFormsModule,
  Validators,
} from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { finalize, forkJoin, Observable } from 'rxjs';

import { apiErrorMessage } from '../../../core/api/api-error';
import {
  BrokerProductCondition,
  BrokerRequest,
  BrokerRequestInput,
} from '../../../core/broker-request.models';
import {
  brokerRequestIdempotencyKey,
  BrokerRequestService,
} from '../../../core/broker-request.service';
import { I18nService } from '../../../core/i18n/i18n.service';
import { TranslationKey } from '../../../core/i18n/locales/en';
import { TranslatePipe } from '../../../core/i18n/translate.pipe';
import { parseMoneyToMinor } from '../../../core/listing-money';
import {
  MarketCountry,
  MarketReferenceCatalog,
} from '../../../core/market.models';
import { MarketReferenceService } from '../../../core/market-reference.service';
import {
  ProductCategoryOption,
} from '../../../core/monitoring.models';
import { MonitoringService } from '../../../core/monitoring.service';

@Component({
  selector: 'app-broker-request-form-page',
  imports: [ReactiveFormsModule, RouterLink, TranslatePipe],
  templateUrl: './broker-request-form.page.html',
  styleUrl: './broker-request-form.page.scss',
})
export class BrokerRequestFormPage implements OnInit {
  private readonly brokerRequests = inject(BrokerRequestService);
  private readonly markets = inject(MarketReferenceService);
  private readonly monitoring = inject(MonitoringService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly formBuilder = inject(FormBuilder);
  private readonly i18n = inject(I18nService);
  private readonly requestId = this.route.snapshot.paramMap.get('id');

  protected readonly record = signal<BrokerRequest | null>(null);
  protected readonly catalog = signal<MarketReferenceCatalog | null>(null);
  protected readonly categories = signal<readonly ProductCategoryOption[]>([]);
  protected readonly loading = signal(true);
  protected readonly saving = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly editing = computed(() => this.requestId !== null);
  protected readonly countries = computed<readonly MarketCountry[]>(() =>
    (this.catalog()?.continents ?? []).flatMap(
      (continent) => continent.countries,
    ),
  );
  protected readonly conditions: readonly BrokerProductCondition[] = [
    'any',
    'new',
    'used',
    'refurbished',
  ];
  protected readonly minDate = new Date().toISOString().slice(0, 10);

  protected readonly form = this.formBuilder.nonNullable.group({
    title: ['', [Validators.required, Validators.minLength(3), Validators.maxLength(160)]],
    product_category_id: [''],
    product_description: [
      '',
      [Validators.required, Validators.minLength(20), Validators.maxLength(3000)],
    ],
    brand_preference: ['', [Validators.maxLength(120)]],
    model_preference: ['', [Validators.maxLength(160)]],
    condition_preference: ['any' as BrokerProductCondition, [Validators.required]],
    quantity: [1, [Validators.required, Validators.min(1), Validators.max(1000)]],
    budget: [''],
    budget_currency_code: [''],
    target_country_codes: [[] as string[], [Validators.required]],
    needed_by: [''],
    notes: ['', [Validators.maxLength(2000)]],
  });

  ngOnInit(): void {
    const request: Observable<BrokerRequest | null> =
      this.requestId === null
        ? new Observable((subscriber) => {
            subscriber.next(null);
            subscriber.complete();
          })
        : this.brokerRequests.show(this.requestId);

    forkJoin({
      request,
      catalog: this.markets.catalog(),
      categories: this.monitoring.productCategories(),
    })
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: ({ request: record, catalog, categories }) => {
          this.catalog.set(catalog);
          this.categories.set(categories);
          this.record.set(record);

          if (record !== null) {
            this.populate(record, catalog);
          }
        },
        error: (error: unknown) =>
          this.error.set(
            apiErrorMessage(
              error,
              this.i18n.translate('brokerRequest.form.loadError'),
            ),
          ),
      });
  }

  protected save(): void {
    if (this.form.invalid || this.saving()) {
      this.form.markAllAsTouched();
      return;
    }

    const value = this.form.getRawValue();
    const currency = this.catalog()?.currencies.find(
      (item) => item.code === value.budget_currency_code,
    );
    const budget = value.budget.trim();
    const budgetMinor =
      budget === ''
        ? null
        : parseMoneyToMinor(budget, currency?.minor_unit ?? 2);

    if (
      (budget !== '' && (budgetMinor === null || currency === undefined)) ||
      value.target_country_codes.length === 0
    ) {
      this.error.set(this.i18n.translate('brokerRequest.form.moneyMarketError'));
      return;
    }

    const input: BrokerRequestInput = {
      title: value.title.trim(),
      product_category_id: value.product_category_id || null,
      product_description: value.product_description.trim(),
      brand_preference: value.brand_preference.trim() || null,
      model_preference: value.model_preference.trim() || null,
      condition_preference: value.condition_preference,
      quantity: value.quantity,
      budget_max_minor: budgetMinor,
      budget_currency_code: budget === '' ? null : value.budget_currency_code,
      target_country_codes: value.target_country_codes,
      needed_by: value.needed_by || null,
      notes: value.notes.trim() || null,
      idempotency_key: brokerRequestIdempotencyKey(),
      ...(this.record() === null
        ? {}
        : { expected_current_event_id: this.record()!.current_event_id }),
    };
    const operation =
      this.record() === null
        ? this.brokerRequests.create(input)
        : this.brokerRequests.update(this.record()!.id, input);

    this.saving.set(true);
    this.error.set(null);
    operation.pipe(finalize(() => this.saving.set(false))).subscribe({
      next: (saved) => {
        void this.router.navigate(['/app/broker-requests', saved.id]);
      },
      error: (error: unknown) =>
        this.error.set(
          apiErrorMessage(
            error,
            this.i18n.translate('brokerRequest.form.saveError'),
          ),
        ),
    });
  }

  protected countryName(country: MarketCountry): string {
    return this.i18n.regionName(country.code, country.name);
  }

  protected conditionLabel(condition: BrokerProductCondition): string {
    return this.i18n.translate(
      `brokerRequest.condition.${condition}` as TranslationKey,
    );
  }

  private populate(
    request: BrokerRequest,
    catalog: MarketReferenceCatalog,
  ): void {
    const currency = catalog.currencies.find(
      (item) => item.code === request.budget_currency_code,
    );
    const budget =
      request.budget_max_minor === null
        ? ''
        : (
            request.budget_max_minor /
            10 ** (currency?.minor_unit ?? 2)
          ).toFixed(currency?.minor_unit ?? 2);

    this.form.patchValue({
      title: request.title,
      product_category_id: request.product_category_id ?? '',
      product_description: request.product_description,
      brand_preference: request.brand_preference ?? '',
      model_preference: request.model_preference ?? '',
      condition_preference: request.condition_preference,
      quantity: request.quantity,
      budget,
      budget_currency_code: request.budget_currency_code ?? '',
      target_country_codes: [...request.target_country_codes],
      needed_by: request.needed_by ?? '',
      notes: request.notes ?? '',
    });
  }
}
