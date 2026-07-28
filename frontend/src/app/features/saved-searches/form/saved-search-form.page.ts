import { Component, computed, effect, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { finalize, forkJoin, of } from 'rxjs';

import { apiErrorMessage } from '../../../core/api/api-error';
import { I18nService } from '../../../core/i18n/i18n.service';
import { TranslatePipe } from '../../../core/i18n/translate.pipe';
import { parseMoneyToMinor } from '../../../core/listing-money';
import {
  MarketReferenceCatalog,
  OrganizationMarketPreferences,
} from '../../../core/market.models';
import { MarketReferenceService } from '../../../core/market-reference.service';
import {
  CatalogMatchScope,
  CatalogReference,
  ProductCategoryOption,
  ProductReference,
  SavedSearch,
  SavedSearchInput,
  SavedSearchNotificationChannel,
} from '../../../core/monitoring.models';
import {
  monitoringIdempotencyKey,
  MonitoringService,
} from '../../../core/monitoring.service';
import { OrganizationContextService } from '../../../core/organizations/organization-context.service';
import { SubscriptionService } from '../../../core/subscription.service';

interface CatalogSelection {
  readonly category: CatalogReference | null;
  readonly brand: CatalogReference | null;
  readonly model: (CatalogReference & { readonly model_number: string }) | null;
}

@Component({
  selector: 'app-saved-search-form-page',
  imports: [ReactiveFormsModule, RouterLink, TranslatePipe],
  templateUrl: './saved-search-form.page.html',
  styleUrl: './saved-search-form.page.scss',
})
export class SavedSearchFormPage {
  private readonly monitoring = inject(MonitoringService);
  private readonly markets = inject(MarketReferenceService);
  private readonly organizations = inject(OrganizationContextService);
  private readonly subscriptions = inject(SubscriptionService);
  private readonly formBuilder = inject(FormBuilder);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly i18n = inject(I18nService);
  private readonly savedSearchId = this.route.snapshot.paramMap.get('id');
  private loadedOrganizationId: string | null = null;

  protected readonly record = signal<SavedSearch | null>(null);
  protected readonly catalog = signal<MarketReferenceCatalog | null>(null);
  protected readonly categories = signal<readonly ProductCategoryOption[]>([]);
  protected readonly productResults = signal<readonly ProductReference[]>([]);
  protected readonly selectedCatalog = signal<CatalogSelection | null>(null);
  protected readonly loading = signal(true);
  protected readonly saving = signal(false);
  protected readonly searchingProducts = signal(false);
  protected readonly emailEnabled = signal(false);
  protected readonly telegramEnabled = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly productError = signal<string | null>(null);
  protected readonly isEditing = computed(() => this.savedSearchId !== null);
  protected readonly canManage = computed(
    () =>
      this.organizations
        .activeOrganization()
        ?.capabilities.includes('saved-searches.manage') === true,
  );

  protected readonly form = this.formBuilder.nonNullable.group({
    title: ['', [Validators.required, Validators.maxLength(120)]],
    active: [true],
    catalog_match_scope: ['category' as CatalogMatchScope],
    product_category_id: [''],
    product_query: ['', [Validators.maxLength(120)]],
    minimum_price: [''],
    maximum_price: [''],
    price_currency_code: [''],
    continent_code: [''],
    country_codes: [[] as string[]],
    city: ['', [Validators.maxLength(120)]],
    radius_km: [''],
    include_cross_border: [false],
    required_keywords: ['', [Validators.maxLength(1000)]],
    excluded_keywords: ['', [Validators.maxLength(1000)]],
    minimum_profit: [''],
    profit_currency_code: [''],
    minimum_margin_percent: [''],
    minimum_deal_score: [''],
    maximum_risk_score: [''],
    email_notifications: [false],
    telegram_notifications: [false],
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

  protected searchProducts(): void {
    const query = this.form.controls.product_query.value.trim();

    if (query.length < 2) {
      this.productError.set(
        this.i18n.translate('savedSearchForm.productQueryMinimum'),
      );
      return;
    }

    this.searchingProducts.set(true);
    this.productError.set(null);
    this.monitoring
      .searchProducts(query)
      .pipe(finalize(() => this.searchingProducts.set(false)))
      .subscribe({
        next: (products) => this.productResults.set(products),
        error: (error: unknown) => {
          this.productError.set(
            apiErrorMessage(
              error,
              this.i18n.translate('savedSearchForm.productSearchError'),
            ),
          );
        },
      });
  }

  protected selectProduct(product: ProductReference): void {
    this.selectedCatalog.set({
      category: product.category,
      brand: product.brand,
      model: {
        id: product.id,
        name: product.name,
        model_number: product.model_number,
      },
    });
    this.form.patchValue({
      catalog_match_scope: 'model',
      product_category_id: product.category.id,
      product_query: `${product.brand.name} ${product.name}`,
    });
    this.productResults.set([]);
    this.productError.set(null);
  }

  protected clearProduct(): void {
    this.selectedCatalog.set(null);
    this.productResults.set([]);
    this.form.patchValue({
      product_query: '',
      catalog_match_scope: 'category',
    });
  }

  protected submit(): void {
    if (!this.canManage()) {
      this.error.set(this.i18n.translate('savedSearchForm.roleError'));
      return;
    }

    if (this.form.invalid) {
      this.form.markAllAsTouched();
      this.error.set(this.i18n.translate('savedSearchForm.validationError'));
      return;
    }

    try {
      const input = this.buildInput();
      this.saving.set(true);
      this.error.set(null);
      const request =
        this.savedSearchId === null
          ? this.monitoring.createSavedSearch(input)
          : this.monitoring.updateSavedSearch(this.savedSearchId, input);

      request.pipe(finalize(() => this.saving.set(false))).subscribe({
        next: (savedSearch) => {
          void this.router.navigate(['/app/saved-searches', savedSearch.id]);
        },
        error: (error: unknown) => {
          this.error.set(
            apiErrorMessage(
              error,
              this.i18n.translate('savedSearchForm.saveError'),
            ),
          );
        },
      });
    } catch (error: unknown) {
      if (
        error instanceof Error &&
        error.message === 'catalog-selection-required'
      ) {
        this.error.set(null);
        return;
      }

      if (error instanceof Error && error.message === 'invalid-price-range') {
        this.error.set(
          this.i18n.translate('savedSearchForm.priceRangeError'),
        );
        return;
      }

      if (
        error instanceof Error &&
        ['currency-required', 'currency-not-found'].includes(error.message)
      ) {
        this.error.set(this.i18n.translate('savedSearchForm.currencyError'));
        return;
      }

      this.error.set(this.i18n.translate('savedSearchForm.numberError'));
    }
  }

  protected continentName(code: string, fallback: string): string {
    return this.i18n.continentName(code, fallback);
  }

  protected countryName(code: string, fallback: string): string {
    return this.i18n.regionName(code, fallback);
  }

  protected currencyName(code: string, fallback: string): string {
    return this.i18n.currencyName(code, fallback);
  }

  private load(): void {
    this.loading.set(true);
    this.error.set(null);
    forkJoin({
      catalog: this.markets.catalog(),
      preferences: this.markets.preferences(),
      categories: this.monitoring.productCategories(),
      subscription: this.subscriptions.current(),
      telegram: this.monitoring.telegramConnection(),
      record:
        this.savedSearchId === null
          ? of(null)
          : this.monitoring.savedSearch(this.savedSearchId),
    })
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: ({
          catalog,
          preferences,
          categories,
          subscription,
          telegram,
          record,
        }) => {
          this.catalog.set(catalog);
          this.categories.set(categories);
          this.record.set(record);
          const emailEnabled =
            subscription.features.find(
              (feature) => feature.code === 'notifications.email',
            )?.enabled === true;
          this.emailEnabled.set(emailEnabled);
          this.telegramEnabled.set(telegram.can_enable_delivery);

          if (emailEnabled) {
            this.form.controls.email_notifications.enable({
              emitEvent: false,
            });
          } else {
            this.form.controls.email_notifications.disable({
              emitEvent: false,
            });
          }

          if (telegram.can_enable_delivery) {
            this.form.controls.telegram_notifications.enable({
              emitEvent: false,
            });
          } else {
            this.form.controls.telegram_notifications.disable({
              emitEvent: false,
            });
          }

          if (record === null) {
            this.applyDefaults(catalog, preferences);
          } else {
            this.applyRecord(catalog, record);
          }
        },
        error: (error: unknown) => {
          this.error.set(
            apiErrorMessage(
              error,
              this.i18n.translate('savedSearchForm.loadError'),
            ),
          );
        },
      });
  }

  private applyDefaults(
    catalog: MarketReferenceCatalog,
    preferences: OrganizationMarketPreferences,
  ): void {
    const homeContinent =
      catalog.continents.find((continent) =>
        continent.countries.some(
          (country) => country.code === preferences.home_country_code,
        ),
      )?.code ?? '';

    this.form.reset({
      title: '',
      active: true,
      catalog_match_scope: 'category',
      product_category_id: '',
      product_query: '',
      minimum_price: '',
      maximum_price: '',
      price_currency_code: preferences.reporting_currency_code ?? '',
      continent_code: homeContinent,
      country_codes: [...preferences.country_codes],
      city: '',
      radius_km: '',
      include_cross_border: preferences.include_cross_border,
      required_keywords: '',
      excluded_keywords: '',
      minimum_profit: '',
      profit_currency_code: preferences.reporting_currency_code ?? '',
      minimum_margin_percent: '',
      minimum_deal_score: '',
      maximum_risk_score: '',
      email_notifications: this.emailEnabled(),
      telegram_notifications: false,
    });
    this.selectedCatalog.set(null);
  }

  private applyRecord(
    catalog: MarketReferenceCatalog,
    record: SavedSearch,
  ): void {
    const version = record.current_version;
    const criteria = version.criteria;
    const scope: CatalogMatchScope =
      criteria.product_model_id !== null
        ? 'model'
        : criteria.brand_id !== null
          ? 'brand'
          : 'category';
    const selection: CatalogSelection = {
      category: version.catalog.category ?? null,
      brand: version.catalog.brand ?? null,
      model: version.catalog.model ?? null,
    };
    const selectionLabel =
      selection.model !== null && selection.brand !== null
        ? `${selection.brand.name} ${selection.model.name}`
        : (selection.brand?.name ?? '');

    this.form.reset({
      title: record.title,
      active: record.active,
      catalog_match_scope: scope,
      product_category_id: criteria.product_category_id ?? '',
      product_query: selectionLabel,
      minimum_price: this.moneyInput(
        catalog,
        criteria.minimum_price_minor,
        criteria.price_currency_code,
      ),
      maximum_price: this.moneyInput(
        catalog,
        criteria.maximum_price_minor,
        criteria.price_currency_code,
      ),
      price_currency_code: criteria.price_currency_code ?? '',
      continent_code: criteria.continent_code ?? '',
      country_codes: [...criteria.country_codes],
      city: criteria.city ?? '',
      radius_km: this.numberInput(criteria.radius_km),
      include_cross_border: criteria.include_cross_border,
      required_keywords: criteria.required_keywords.join(', '),
      excluded_keywords: criteria.excluded_keywords.join(', '),
      minimum_profit: this.moneyInput(
        catalog,
        criteria.minimum_profit_minor,
        criteria.profit_currency_code,
      ),
      profit_currency_code: criteria.profit_currency_code ?? '',
      minimum_margin_percent: this.basisPointsInput(
        criteria.minimum_margin_basis_points,
      ),
      minimum_deal_score: this.basisPointsInput(
        criteria.minimum_deal_score_basis_points,
      ),
      maximum_risk_score: this.numberInput(criteria.maximum_risk_score),
      email_notifications:
        version.notification_channels.includes('email'),
      telegram_notifications:
        version.notification_channels.includes('telegram'),
    });
    this.selectedCatalog.set(
      selection.category !== null ||
        selection.brand !== null ||
        selection.model !== null
        ? selection
        : null,
    );
  }

  private buildInput(): SavedSearchInput {
    const value = this.form.getRawValue();
    const selection = this.selectedCatalog();
    const scope = value.catalog_match_scope;
    const categoryId =
      scope === 'category'
        ? (value.product_category_id || selection?.category?.id || null)
        : scope === 'model'
          ? (selection?.category?.id ?? null)
          : null;
    const brandId =
      scope === 'brand' || scope === 'model'
        ? (selection?.brand?.id ?? null)
        : null;
    const modelId = scope === 'model' ? (selection?.model?.id ?? null) : null;

    if (
      (scope === 'brand' && brandId === null) ||
      (scope === 'model' && modelId === null)
    ) {
      this.productError.set(
        this.i18n.translate('savedSearchForm.selectCatalogResult'),
      );
      throw new Error('catalog-selection-required');
    }

    const minimumPrice = this.money(
      value.minimum_price,
      value.price_currency_code,
    );
    const maximumPrice = this.money(
      value.maximum_price,
      value.price_currency_code,
    );

    if (
      minimumPrice !== null &&
      maximumPrice !== null &&
      minimumPrice > maximumPrice
    ) {
      throw new Error('invalid-price-range');
    }

    const existingChannels =
      this.record()?.current_version.notification_channels ?? [];
    const notificationChannels: SavedSearchNotificationChannel[] = [
      'in_app',
    ];

    if (
      value.email_notifications
      && (
        this.emailEnabled()
        || existingChannels.includes('email')
      )
    ) {
      notificationChannels.push('email');
    }

    if (
      value.telegram_notifications
      && (
        this.telegramEnabled()
        || existingChannels.includes('telegram')
      )
    ) {
      notificationChannels.push('telegram');
    }

    return {
      title: value.title.trim(),
      active: value.active,
      product_category_id: categoryId,
      brand_id: brandId,
      product_model_id: modelId,
      minimum_price_minor: minimumPrice,
      maximum_price_minor: maximumPrice,
      price_currency_code:
        minimumPrice === null && maximumPrice === null
          ? null
          : value.price_currency_code,
      continent_code: value.continent_code || null,
      country_codes: value.country_codes,
      city: value.city.trim() || null,
      radius_km: this.optionalInteger(value.radius_km),
      include_cross_border: value.include_cross_border,
      required_keywords: this.keywords(value.required_keywords),
      excluded_keywords: this.keywords(value.excluded_keywords),
      minimum_profit_minor: this.money(
        value.minimum_profit,
        value.profit_currency_code,
      ),
      profit_currency_code:
        value.minimum_profit.trim() === '' ? null : value.profit_currency_code,
      minimum_margin_basis_points: this.basisPoints(
        value.minimum_margin_percent,
      ),
      minimum_deal_score_basis_points: this.basisPoints(
        value.minimum_deal_score,
      ),
      maximum_risk_score: this.optionalInteger(value.maximum_risk_score),
      notification_channels: notificationChannels,
      reason_code:
        this.savedSearchId === null
          ? 'saved_search_created'
          : 'saved_search_updated',
      idempotency_key: monitoringIdempotencyKey(),
      ...(this.savedSearchId === null
        ? {}
        : {
            expected_current_version_id:
              this.record()?.current_version_id ?? '',
          }),
    };
  }

  private money(value: string, currencyCode: string): number | null {
    const normalized = value.trim();

    if (normalized === '') {
      return null;
    }

    if (currencyCode === '') {
      throw new Error('currency-required');
    }

    const minorUnit =
      this.catalog()?.currencies.find(
        (currency) => currency.code === currencyCode,
      )?.minor_unit;

    if (minorUnit === undefined) {
      throw new Error('currency-not-found');
    }

    return parseMoneyToMinor(normalized, minorUnit);
  }

  private moneyInput(
    catalog: MarketReferenceCatalog,
    amount: number | null,
    currencyCode: string | null,
  ): string {
    if (amount === null || currencyCode === null) {
      return '';
    }

    const minorUnit =
      catalog.currencies.find((currency) => currency.code === currencyCode)
        ?.minor_unit ?? 2;

    return (amount / 10 ** minorUnit).toFixed(minorUnit);
  }

  private basisPoints(value: string | number): number | null {
    const normalized = String(value).trim();

    if (normalized === '') {
      return null;
    }

    const numeric = Number(normalized);

    if (!Number.isFinite(numeric)) {
      throw new Error('invalid-basis-points');
    }

    return Math.round(numeric * 100);
  }

  private basisPointsInput(value: number | null): string {
    return value === null ? '' : String(value / 100);
  }

  private optionalInteger(value: string | number): number | null {
    const normalized = String(value).trim();

    if (normalized === '') {
      return null;
    }

    const numeric = Number(normalized);

    if (!Number.isInteger(numeric)) {
      throw new Error('invalid-integer');
    }

    return numeric;
  }

  private numberInput(value: number | null): string {
    return value === null ? '' : String(value);
  }

  private keywords(value: string): readonly string[] {
    return [
      ...new Set(
        value
          .split(',')
          .map((keyword) => keyword.trim().toLocaleLowerCase())
          .filter((keyword) => keyword !== ''),
      ),
    ];
  }
}
