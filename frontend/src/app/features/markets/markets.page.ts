import { Component, computed, inject, OnInit, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { finalize, forkJoin } from 'rxjs';

import { I18nService } from '../../core/i18n/i18n.service';
import { TranslatePipe } from '../../core/i18n/translate.pipe';
import { MarketReferenceService } from '../../core/market-reference.service';
import {
  MarketContinent,
  MarketReferenceCatalog,
  MeasurementSystem,
} from '../../core/market.models';
import { OrganizationContextService } from '../../core/organizations/organization-context.service';

@Component({
  selector: 'app-markets-page',
  imports: [ReactiveFormsModule, TranslatePipe],
  templateUrl: './markets.page.html',
  styleUrl: './markets.page.scss',
})
export class MarketsPage implements OnInit {
  private readonly markets = inject(MarketReferenceService);
  private readonly organizations = inject(OrganizationContextService);
  private readonly formBuilder = inject(FormBuilder);
  private readonly i18n = inject(I18nService);

  protected readonly catalog = signal<MarketReferenceCatalog | null>(null);
  protected readonly selectedCountries = signal<ReadonlySet<string>>(new Set());
  protected readonly search = signal('');
  protected readonly loading = signal(true);
  protected readonly saving = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly success = signal<string | null>(null);
  protected readonly canSave = computed(() => {
    const organization = this.organizations.activeOrganization();
    return (
      organization?.role === 'owner' ||
      organization?.capabilities.includes('organization.update') === true
    );
  });
  protected readonly filteredContinents = computed(() => {
    const query = this.search().trim().toLocaleLowerCase();
    const continents = this.catalog()?.continents ?? [];
    if (!query) return continents;

    return continents
      .map((continent) => ({
        ...continent,
        countries: continent.countries.filter(
          (country) =>
            this.countryName(country.code, country.name)
              .toLocaleLowerCase(this.i18n.locale())
              .includes(query) ||
            country.code.toLocaleLowerCase().includes(query),
        ),
      }))
      .filter((continent) => continent.countries.length > 0);
  });

  protected readonly form = this.formBuilder.nonNullable.group({
    home_country_code: [''],
    reporting_currency_code: [''],
    locale: ['en', [Validators.required, Validators.maxLength(35)]],
    timezone: ['UTC', [Validators.required, Validators.maxLength(64)]],
    measurement_system: ['metric' as MeasurementSystem, [Validators.required]],
    include_cross_border: [false],
  });

  ngOnInit(): void {
    forkJoin({
      catalog: this.markets.catalog(),
      preferences: this.markets.preferences(),
    })
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: ({ catalog, preferences }) => {
          this.catalog.set(catalog);
          this.selectedCountries.set(new Set(preferences.country_codes));
          this.form.setValue({
            home_country_code: preferences.home_country_code ?? '',
            reporting_currency_code: preferences.reporting_currency_code ?? '',
            locale: preferences.locale,
            timezone: preferences.timezone,
            measurement_system: preferences.measurement_system,
            include_cross_border: preferences.include_cross_border,
          });
        },
        error: () => this.error.set(this.i18n.translate('market.loadError')),
      });
  }

  protected updateSearch(event: Event): void {
    this.search.set((event.target as HTMLInputElement).value);
  }

  protected toggleCountry(countryCode: string, checked: boolean): void {
    const next = new Set(this.selectedCountries());
    if (checked) {
      next.add(countryCode);
    } else {
      next.delete(countryCode);
    }
    this.selectedCountries.set(next);
  }

  protected selectContinent(continent: MarketContinent): void {
    const countryCodes = continent.countries.map((country) => country.code);
    const next = new Set(this.selectedCountries());
    const allSelected = countryCodes.every((code) => next.has(code));
    countryCodes.forEach((code) => (allSelected ? next.delete(code) : next.add(code)));
    this.selectedCountries.set(next);
  }

  protected useBrowserTimezone(): void {
    this.form.controls.timezone.setValue(Intl.DateTimeFormat().resolvedOptions().timeZone);
  }

  protected save(): void {
    if (this.form.invalid || this.selectedCountries().size === 0 || !this.canSave()) {
      this.form.markAllAsTouched();
      if (this.selectedCountries().size === 0) {
        this.error.set(this.i18n.translate('market.countryRequired'));
      }
      return;
    }

    const value = this.form.getRawValue();
    this.saving.set(true);
    this.error.set(null);
    this.success.set(null);
    this.markets
      .save({
        ...value,
        home_country_code: value.home_country_code || null,
        reporting_currency_code: value.reporting_currency_code || null,
        country_codes: [...this.selectedCountries()],
      })
      .pipe(finalize(() => this.saving.set(false)))
      .subscribe({
        next: (saved) => {
          this.selectedCountries.set(new Set(saved.country_codes));
          this.success.set(this.i18n.translate('market.saved'));
        },
        error: () => this.error.set(this.i18n.translate('market.saveError')),
      });
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
