import { Component, computed, effect, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { concatMap, finalize, forkJoin, from, tap } from 'rxjs';

import { apiErrorMessage } from '../../../core/api/api-error';
import { I18nService } from '../../../core/i18n/i18n.service';
import { TranslatePipe } from '../../../core/i18n/translate.pipe';
import { parseMoneyToMinor } from '../../../core/listing-money';
import {
  ListingImageKind,
  ListingInput,
  ListingStatus,
  MarketplaceSource,
} from '../../../core/listing.models';
import { ListingService } from '../../../core/listing.service';
import {
  MarketReferenceCatalog,
  OrganizationMarketPreferences,
} from '../../../core/market.models';
import { MarketReferenceService } from '../../../core/market-reference.service';
import { OrganizationContextService } from '../../../core/organizations/organization-context.service';

interface UploadBatch {
  readonly kind: ListingImageKind;
  readonly files: readonly File[];
}

@Component({
  selector: 'app-listing-intake-page',
  imports: [ReactiveFormsModule, RouterLink, TranslatePipe],
  templateUrl: './listing-intake.page.html',
  styleUrl: './listing-intake.page.scss',
})
export class ListingIntakePage {
  private readonly listings = inject(ListingService);
  private readonly markets = inject(MarketReferenceService);
  private readonly organizations = inject(OrganizationContextService);
  private readonly formBuilder = inject(FormBuilder);
  private readonly router = inject(Router);
  private readonly i18n = inject(I18nService);
  private loadedOrganizationId: string | null = null;

  protected readonly catalog = signal<MarketReferenceCatalog | null>(null);
  protected readonly sources = signal<readonly MarketplaceSource[]>([]);
  protected readonly loading = signal(true);
  protected readonly saving = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly priceError = signal<string | null>(null);
  protected readonly productImages = signal<readonly File[]>([]);
  protected readonly screenshots = signal<readonly File[]>([]);
  protected readonly createdListingId = signal<string | null>(null);
  protected readonly uploadedKinds = signal<ReadonlySet<ListingImageKind>>(new Set());
  protected readonly canManage = computed(
    () =>
      this.organizations.activeOrganization()?.capabilities.includes('listings.manage') ===
      true,
  );

  protected readonly form = this.formBuilder.nonNullable.group({
    marketplace_source_key: ['manual', [Validators.required]],
    source_url: ['', [Validators.maxLength(2048)]],
    external_id: ['', [Validators.maxLength(128)]],
    marketplace_name: ['', [Validators.required, Validators.maxLength(160)]],
    title: ['', [Validators.required, Validators.maxLength(240)]],
    description: ['', [Validators.maxLength(50000)]],
    price: ['', [Validators.maxLength(32)]],
    currency_code: [''],
    seller_information: ['', [Validators.maxLength(5000)]],
    location: ['', [Validators.maxLength(255)]],
    source_country_code: ['', [Validators.required]],
    target_country_code: ['', [Validators.required]],
    status: ['active' as ListingStatus, [Validators.required]],
    notes: ['', [Validators.maxLength(10000)]],
  });

  constructor() {
    effect(() => {
      const organizationId = this.organizations.activeOrganization()?.id ?? null;

      if (organizationId !== null && organizationId !== this.loadedOrganizationId) {
        this.loadedOrganizationId = organizationId;
        this.resetForOrganization();
        this.loadReferenceData();
      }
    });
  }

  protected retryReferenceData(): void {
    this.loadReferenceData();
  }

  protected selectFiles(event: Event, kind: ListingImageKind): void {
    const input = event.target as HTMLInputElement;
    const files = Array.from(input.files ?? []);
    const maximum = kind === 'product' ? 10 : 5;
    const allowedTypes = new Set(['image/jpeg', 'image/png', 'image/webp']);
    const invalid = files.find(
      (file) => !allowedTypes.has(file.type) || file.size > 10 * 1024 * 1024,
    );

    if (files.length > maximum) {
      this.error.set(
        this.i18n.translate('listingIntake.imageLimit', {
          maximum,
          kind: this.i18n.translate(
            kind === 'product'
              ? 'listingIntake.productImages'
              : 'listingIntake.screenshots',
          ),
        }),
      );
      input.value = '';
      return;
    }

    if (invalid !== undefined) {
      this.error.set(
        this.i18n.translate('listingIntake.imagePolicyError'),
      );
      input.value = '';
      return;
    }

    this.error.set(null);
    if (kind === 'product') {
      this.productImages.set(files);
    } else {
      this.screenshots.set(files);
    }
  }

  protected submit(): void {
    if (!this.canManage()) {
      this.error.set(this.i18n.translate('listingIntake.roleError'));
      return;
    }

    if (this.createdListingId() !== null) {
      this.uploadPending(this.createdListingId() as string);
      return;
    }

    if (this.form.invalid) {
      this.form.markAllAsTouched();
      this.error.set(this.i18n.translate('listingIntake.requiredError'));
      return;
    }

    const value = this.form.getRawValue();
    let askingPriceMinor: number | null = null;
    this.priceError.set(null);

    if (value.price.trim() !== '' && value.currency_code === '') {
      this.priceError.set(this.i18n.translate('listingIntake.choosePriceCurrency'));
      this.error.set(this.i18n.translate('listingIntake.priceReview'));
      return;
    }

    try {
      if (value.price.trim() !== '') {
        const minorUnit =
          this.catalog()?.currencies.find(
            (currency) => currency.code === value.currency_code,
          )?.minor_unit ?? 2;
        askingPriceMinor = parseMoneyToMinor(value.price, minorUnit);
      }
    } catch {
      this.priceError.set(this.i18n.translate('listingIntake.priceInvalid'));
      this.error.set(this.i18n.translate('listingIntake.priceReview'));
      return;
    }

    const input: ListingInput = {
      marketplace_source_key: value.marketplace_source_key,
      source_url: this.nullIfBlank(value.source_url),
      external_id: this.nullIfBlank(value.external_id),
      marketplace_name: value.marketplace_name.trim(),
      title: value.title.trim(),
      description: this.nullIfBlank(value.description),
      asking_price_minor: askingPriceMinor,
      currency_code: askingPriceMinor === null ? null : value.currency_code,
      seller_information: this.nullIfBlank(value.seller_information),
      location: this.nullIfBlank(value.location),
      source_country_code: value.source_country_code,
      target_country_code: value.target_country_code,
      status: value.status,
      notes: this.nullIfBlank(value.notes),
    };

    this.saving.set(true);
    this.error.set(null);
    this.listings.create(input).subscribe({
      next: (listing) => {
        this.createdListingId.set(listing.id);
        this.uploadPending(listing.id);
      },
      error: (error: unknown) => {
        this.saving.set(false);
        this.error.set(
          apiErrorMessage(error, this.i18n.translate('listingIntake.saveError')),
        );
      },
    });
  }

  private loadReferenceData(): void {
    this.loading.set(true);
    this.error.set(null);

    forkJoin({
      catalog: this.markets.catalog(),
      preferences: this.markets.preferences(),
      sources: this.listings.sources(),
    })
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: ({ catalog, preferences, sources }) => {
          const directSources = sources.filter(
            (source) => source.connector_type === 'manual' && source.available,
          );
          this.catalog.set(catalog);
          this.sources.set(directSources);
          this.applyDefaults(catalog, preferences, directSources);
        },
        error: (error: unknown) => {
          this.error.set(
            apiErrorMessage(
              error,
              this.i18n.translate('listingIntake.referenceError'),
            ),
          );
        },
      });
  }

  private resetForOrganization(): void {
    this.form.reset({
      marketplace_source_key: 'manual',
      source_url: '',
      external_id: '',
      marketplace_name: '',
      title: '',
      description: '',
      price: '',
      currency_code: '',
      seller_information: '',
      location: '',
      source_country_code: '',
      target_country_code: '',
      status: 'active',
      notes: '',
    });
    this.catalog.set(null);
    this.sources.set([]);
    this.productImages.set([]);
    this.screenshots.set([]);
    this.createdListingId.set(null);
    this.uploadedKinds.set(new Set());
    this.priceError.set(null);
  }

  private applyDefaults(
    catalog: MarketReferenceCatalog,
    preferences: OrganizationMarketPreferences,
    sources: readonly MarketplaceSource[],
  ): void {
    const countries = catalog.continents.flatMap((continent) => continent.countries);
    const sourceCountry =
      preferences.home_country_code ?? preferences.country_codes[0] ?? '';
    const targetCountry =
      preferences.country_codes.find((code) => code !== sourceCountry) ?? sourceCountry;
    const countryCurrency = countries.find(
      (country) => country.code === sourceCountry,
    )?.currency_code;

    this.form.patchValue({
      marketplace_source_key:
        sources.find((source) => source.key === 'manual')?.key ?? sources[0]?.key ?? '',
      source_country_code: sourceCountry,
      target_country_code: targetCountry,
      currency_code: preferences.reporting_currency_code ?? countryCurrency ?? '',
    });
  }

  private uploadPending(listingId: string): void {
    const uploaded = this.uploadedKinds();
    const candidates: UploadBatch[] = [
      { kind: 'product', files: this.productImages() },
      { kind: 'screenshot', files: this.screenshots() },
    ];
    const batches = candidates.filter(
      (batch) => batch.files.length > 0 && !uploaded.has(batch.kind),
    );

    if (batches.length === 0) {
      this.saving.set(false);
      void this.router.navigate(['/app/buy', listingId]);
      return;
    }

    this.saving.set(true);
    this.error.set(null);
    from(batches)
      .pipe(
        concatMap((batch) =>
          this.listings.uploadImages(listingId, batch.kind, batch.files).pipe(
            tap(() => {
              const next = new Set(this.uploadedKinds());
              next.add(batch.kind);
              this.uploadedKinds.set(next);
            }),
          ),
        ),
        finalize(() => this.saving.set(false)),
      )
      .subscribe({
        complete: () => void this.router.navigate(['/app/buy', listingId]),
        error: (error: unknown) => {
          this.error.set(
            apiErrorMessage(
              error,
              this.i18n.translate('listingIntake.uploadError'),
            ),
          );
        },
      });
  }

  private nullIfBlank(value: string): string | null {
    const trimmed = value.trim();

    return trimmed === '' ? null : trimmed;
  }

  protected countryName(code: string, fallback: string): string {
    return this.i18n.regionName(code, fallback);
  }

  protected currencyName(code: string, fallback: string): string {
    return this.i18n.currencyName(code, fallback);
  }

  protected continentName(code: string, fallback: string): string {
    return this.i18n.continentName(code, fallback);
  }
}
