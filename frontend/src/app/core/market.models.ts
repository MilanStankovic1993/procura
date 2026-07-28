export type MeasurementSystem = 'metric' | 'us_customary' | 'uk_mixed';

export interface MarketCountry {
  readonly code: string;
  readonly alpha3_code: string;
  readonly numeric_code: string;
  readonly name: string;
  readonly currency_code: string | null;
  readonly measurement_system: MeasurementSystem;
}

export interface MarketContinent {
  readonly code: string;
  readonly name: string;
  readonly countries: readonly MarketCountry[];
}

export interface MarketCurrency {
  readonly code: string;
  readonly numeric_code: string | null;
  readonly name: string;
  readonly symbol: string;
  readonly minor_unit: number;
  readonly cash_minor_unit: number;
}

export interface MarketReferenceCatalog {
  readonly version: string;
  readonly continents: readonly MarketContinent[];
  readonly currencies: readonly MarketCurrency[];
}

export interface OrganizationMarketPreferences {
  readonly home_country_code: string | null;
  readonly reporting_currency_code: string | null;
  readonly locale: string;
  readonly timezone: string;
  readonly measurement_system: MeasurementSystem;
  readonly include_cross_border: boolean;
  readonly country_codes: readonly string[];
}
