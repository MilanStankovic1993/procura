import { Component, computed, effect, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { finalize, forkJoin } from 'rxjs';

import { apiErrorMessage } from '../../../core/api/api-error';
import { I18nService } from '../../../core/i18n/i18n.service';
import { TranslatePipe } from '../../../core/i18n/translate.pipe';
import {
  CrossBorderPreference,
  DesiredSaleSpeed,
  OwnedProductCondition,
  OwnedProductImageKind,
  OwnedProductInput,
  ProductCategoryReference,
} from '../../../core/owned-product.models';
import { OwnedProductService } from '../../../core/owned-product.service';
import {
  MarketCountry,
  MarketReferenceCatalog,
} from '../../../core/market.models';
import { MarketReferenceService } from '../../../core/market-reference.service';
import { OrganizationContextService } from '../../../core/organizations/organization-context.service';

type IntakeStep = 1 | 2 | 3;

@Component({
  selector: 'app-owned-product-intake-page',
  imports: [ReactiveFormsModule, RouterLink, TranslatePipe],
  templateUrl: './owned-product-intake.page.html',
  styleUrl: './owned-product-intake.page.scss',
})
export class OwnedProductIntakePage {
  private readonly ownedProducts = inject(OwnedProductService);
  private readonly markets = inject(MarketReferenceService);
  private readonly organizations = inject(OrganizationContextService);
  private readonly formBuilder = inject(FormBuilder);
  private readonly router = inject(Router);
  private readonly i18n = inject(I18nService);
  private loadedOrganizationId: string | null = null;

  protected readonly catalog = signal<MarketReferenceCatalog | null>(null);
  protected readonly categories = signal<readonly ProductCategoryReference[]>([]);
  protected readonly loading = signal(true);
  protected readonly saving = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly selectedFiles = signal<readonly File[]>([]);
  protected readonly createdOwnedProductId = signal<string | null>(null);
  protected readonly currentStep = signal<IntakeStep>(1);
  protected readonly furthestStep = signal<IntakeStep>(1);
  protected readonly countrySearch = signal('');
  protected readonly showAllCountries = signal(false);
  protected readonly steps = { product: 1, condition: 2, goal: 3 } as const;
  protected readonly canManage = computed(
    () =>
      this.organizations
        .activeOrganization()
        ?.capabilities.includes('owned-products.manage') === true,
  );

  protected readonly form = this.formBuilder.nonNullable.group({
    product_category_id: [''],
    brand_name: ['', [Validators.maxLength(160)]],
    model_name: ['', [Validators.maxLength(200)]],
    condition: ['unknown' as OwnedProductCondition, [Validators.required]],
    age_months: [
      '',
      [Validators.pattern(/^\d*$/), Validators.max(1200)],
    ],
    accessories_known: [false],
    accessories: ['', [Validators.maxLength(8050)]],
    defects_known: [false],
    defects: ['', [Validators.maxLength(25050)]],
    purchase_history_known: [false],
    purchase_history: ['', [Validators.maxLength(10000)]],
    target_continent_code: ['', [Validators.required]],
    target_country_codes: [[] as string[], [Validators.required]],
    cross_border_preference: [
      'unknown' as CrossBorderPreference,
      [Validators.required],
    ],
    desired_sale_speed: ['unknown' as DesiredSaleSpeed, [Validators.required]],
    status: ['draft' as const, [Validators.required]],
    image_kind: ['product' as OwnedProductImageKind, [Validators.required]],
    notes: ['', [Validators.maxLength(10000)]],
  });

  constructor() {
    effect(() => {
      const organizationId = this.organizations.activeOrganization()?.id ?? null;

      if (organizationId !== null && organizationId !== this.loadedOrganizationId) {
        this.loadedOrganizationId = organizationId;
        this.reset();
        this.loadReferenceData();
      }
    });
  }

  protected retryReferenceData(): void {
    this.loadReferenceData();
  }

  protected countriesForSelectedContinent(): readonly MarketCountry[] {
    const continentCode = this.form.controls.target_continent_code.value;

    return (
      this.catalog()?.continents.find((continent) => continent.code === continentCode)
        ?.countries ?? []
    );
  }

  protected visibleCountries(): readonly MarketCountry[] {
    const query = this.countrySearch().trim().toLocaleLowerCase();
    const selected = new Set(this.form.controls.target_country_codes.value);
    const countries = this.countriesForSelectedContinent().filter(
      (country) =>
        query === '' ||
        this.countryName(country).toLocaleLowerCase().includes(query) ||
        country.code.toLocaleLowerCase().includes(query),
    );
    const sorted = [...countries].sort((left, right) => {
      const selectedDifference = Number(selected.has(right.code)) - Number(selected.has(left.code));

      return selectedDifference || this.countryName(left).localeCompare(this.countryName(right));
    });

    return this.showAllCountries() || query !== '' ? sorted : sorted.slice(0, 12);
  }

  protected countryName(country: MarketCountry): string {
    return this.i18n.regionName(country.code, country.name);
  }

  protected continentName(code: string, fallback: string): string {
    return this.i18n.continentName(code, fallback);
  }

  protected changeContinent(): void {
    const validCodes = new Set(
      this.countriesForSelectedContinent().map((country) => country.code),
    );
    this.form.controls.target_country_codes.setValue(
      this.form.controls.target_country_codes.value.filter((code) =>
        validCodes.has(code),
      ),
    );
    this.countrySearch.set('');
    this.showAllCountries.set(false);
  }

  protected updateCountrySearch(event: Event): void {
    this.countrySearch.set((event.target as HTMLInputElement).value);
  }

  protected toggleCountry(countryCode: string, event: Event): void {
    const checked = (event.target as HTMLInputElement).checked;
    const selected = new Set(this.form.controls.target_country_codes.value);

    if (checked) {
      selected.add(countryCode);
    } else {
      selected.delete(countryCode);
    }

    this.form.controls.target_country_codes.setValue([...selected]);
    this.form.controls.target_country_codes.markAsTouched();
  }

  protected isCountrySelected(countryCode: string): boolean {
    return this.form.controls.target_country_codes.value.includes(countryCode);
  }

  protected conditionLabel(condition: OwnedProductCondition): string {
    const keys = {
      unknown: 'ownedProduct.condition.unknown',
      new: 'ownedProduct.condition.new',
      like_new: 'ownedProduct.condition.like_new',
      used_good: 'ownedProduct.condition.used_good',
      used_fair: 'ownedProduct.condition.used_fair',
      used_poor: 'ownedProduct.condition.used_poor',
      broken: 'ownedProduct.condition.broken',
    } as const;

    return this.i18n.translate(keys[condition]);
  }

  protected goToStep(step: IntakeStep): void {
    if (step <= this.furthestStep()) {
      this.currentStep.set(step);
      this.scrollToTop();
    }
  }

  protected isStepComplete(step: IntakeStep): boolean {
    return this.furthestStep() > step;
  }

  protected canVisitStep(step: IntakeStep): boolean {
    return this.furthestStep() >= step;
  }

  protected nextStep(): void {
    const step = this.currentStep();

    if (step === 3 || (step === 2 && !this.validateConditionStep())) {
      return;
    }

    const next = Math.min(3, step + 1) as IntakeStep;
    this.furthestStep.update((visited) => Math.max(visited, next) as IntakeStep);
    this.currentStep.set(next);
    this.error.set(null);
    this.scrollToTop();
  }

  protected previousStep(): void {
    this.currentStep.set(Math.max(1, this.currentStep() - 1) as IntakeStep);
    this.error.set(null);
    this.scrollToTop();
  }

  protected selectFiles(event: Event): void {
    const input = event.target as HTMLInputElement;
    const files = Array.from(input.files ?? []);
    const allowedTypes = new Set(['image/jpeg', 'image/png', 'image/webp']);
    const invalid = files.find(
      (file) => !allowedTypes.has(file.type) || file.size > 10 * 1024 * 1024,
    );

    if (invalid !== undefined || files.length > 10) {
      this.error.set(this.i18n.translate('ownedProduct.intake.imagePolicyError'));
      input.value = '';
      this.selectedFiles.set([]);
      return;
    }

    this.error.set(null);
    this.selectedFiles.set(files);
  }

  protected submit(): void {
    if (!this.canManage()) {
      this.error.set(this.i18n.translate('ownedProduct.intake.roleError'));
      return;
    }

    if (this.createdOwnedProductId() !== null) {
      this.uploadOrOpen(this.createdOwnedProductId() as string);
      return;
    }

    if (this.form.invalid) {
      this.form.markAllAsTouched();
      this.error.set(this.i18n.translate('ownedProduct.intake.requiredError'));
      return;
    }

    const value = this.form.getRawValue();
    const ageText = String(value.age_months).trim();
    const ageMonths = ageText === ''
      ? null
      : Number(ageText);

    if (!Number.isInteger(ageMonths) && ageMonths !== null) {
      this.error.set(this.i18n.translate('ownedProduct.intake.ageError'));
      return;
    }

    const input: OwnedProductInput = {
      product_category_id: this.nullIfBlank(value.product_category_id),
      brand_name: this.nullIfBlank(value.brand_name),
      model_name: this.nullIfBlank(value.model_name),
      condition: value.condition,
      age_months: ageMonths,
      accessories: value.accessories_known
        ? this.lines(value.accessories)
        : null,
      defects: value.defects_known ? this.lines(value.defects) : null,
      purchase_history_known: value.purchase_history_known,
      purchase_history: value.purchase_history_known
        ? this.nullIfBlank(value.purchase_history)
        : null,
      target_continent_code: value.target_continent_code,
      target_country_codes: value.target_country_codes,
      cross_border_preference: value.cross_border_preference,
      desired_sale_speed: value.desired_sale_speed,
      status: value.status,
      notes: this.nullIfBlank(value.notes),
    };

    this.saving.set(true);
    this.error.set(null);
    this.ownedProducts.create(input).subscribe({
      next: (ownedProduct) => {
        this.createdOwnedProductId.set(ownedProduct.id);
        this.uploadOrOpen(ownedProduct.id);
      },
      error: (error: unknown) => {
        this.saving.set(false);
        this.error.set(
          apiErrorMessage(
            error,
            this.i18n.translate('ownedProduct.intake.saveError'),
          ),
        );
      },
    });
  }

  private uploadOrOpen(ownedProductId: string): void {
    const files = this.selectedFiles();

    if (files.length === 0) {
      this.saving.set(false);
      void this.router.navigate(['/app/sell', ownedProductId]);
      return;
    }

    this.ownedProducts
      .uploadImages(
        ownedProductId,
        this.form.controls.image_kind.value,
        files,
      )
      .pipe(finalize(() => this.saving.set(false)))
      .subscribe({
        next: () => void this.router.navigate(['/app/sell', ownedProductId]),
        error: (error: unknown) => {
          this.error.set(
            apiErrorMessage(
              error,
              this.i18n.translate('ownedProduct.intake.uploadError'),
            ),
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
      categories: this.ownedProducts.categories(),
    })
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: ({ catalog, preferences, categories }) => {
          this.catalog.set(catalog);
          this.categories.set(categories);

          const preferredCodes = preferences.country_codes.filter((code) =>
            catalog.continents.some((continent) =>
              continent.countries.some((country) => country.code === code),
            ),
          );
          const firstCode = preferredCodes[0] ?? preferences.home_country_code;
          const continent = catalog.continents.find((candidate) =>
            candidate.countries.some((country) => country.code === firstCode),
          );

          if (continent !== undefined) {
            const validCodes = new Set(
              continent.countries.map((country) => country.code),
            );
            const targetCodes = preferredCodes.filter((code) =>
              validCodes.has(code),
            );

            this.form.patchValue({
              target_continent_code: continent.code,
              target_country_codes:
                targetCodes.length > 0
                  ? targetCodes
                  : firstCode === null
                    ? []
                    : [firstCode],
              cross_border_preference: preferences.include_cross_border
                ? 'cross_border_allowed'
                : 'unknown',
            });
          }
        },
        error: (error: unknown) => {
          this.error.set(
            apiErrorMessage(
              error,
              this.i18n.translate('ownedProduct.intake.referenceError'),
            ),
          );
        },
      });
  }

  private lines(value: string): readonly string[] {
    return value
      .split(/\r?\n/)
      .map((line) => line.trim())
      .filter((line, index, values) => line !== '' && values.indexOf(line) === index);
  }

  private nullIfBlank(value: string): string | null {
    const trimmed = value.trim();

    return trimmed === '' ? null : trimmed;
  }

  private validateConditionStep(): boolean {
    const ageControl = this.form.controls.age_months;
    ageControl.markAsTouched();

    if (ageControl.invalid) {
      this.error.set(this.i18n.translate('ownedProduct.intake.ageError'));
      return false;
    }

    return true;
  }

  private scrollToTop(): void {
    if (globalThis.document !== undefined) {
      globalThis.document.documentElement.scrollTop = 0;
    }
  }

  private reset(): void {
    this.catalog.set(null);
    this.categories.set([]);
    this.selectedFiles.set([]);
    this.createdOwnedProductId.set(null);
    this.currentStep.set(1);
    this.furthestStep.set(1);
    this.countrySearch.set('');
    this.showAllCountries.set(false);
    this.form.reset({
      product_category_id: '',
      brand_name: '',
      model_name: '',
      condition: 'unknown',
      age_months: '',
      accessories_known: false,
      accessories: '',
      defects_known: false,
      defects: '',
      purchase_history_known: false,
      purchase_history: '',
      target_continent_code: '',
      target_country_codes: [],
      cross_border_preference: 'unknown',
      desired_sale_speed: 'unknown',
      status: 'draft',
      image_kind: 'product',
      notes: '',
    });
  }
}
