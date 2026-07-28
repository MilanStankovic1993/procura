import { DOCUMENT } from '@angular/common';
import { inject, Injectable, signal } from '@angular/core';

import {
  EN_TRANSLATIONS,
  TranslationDictionary,
  TranslationKey,
} from './locales/en';
import {
  LOCALE_OPTIONS,
  resolveSupportedLocale,
  SupportedLocale,
} from './i18n.models';

type TranslationParameters = Readonly<Record<string, string | number>>;

const STORAGE_KEY = 'procura.preferred-locale';

@Injectable({ providedIn: 'root' })
export class I18nService {
  private readonly document = inject(DOCUMENT);
  private readonly activeLocale = signal<SupportedLocale>('en');
  private readonly activeTranslations = signal<TranslationDictionary>(EN_TRANSLATIONS);
  private loadSequence = 0;

  readonly locale = this.activeLocale.asReadonly();
  readonly locales = LOCALE_OPTIONS;

  async initialize(): Promise<void> {
    const storedLocale = this.readStoredLocale();
    const browserLocale =
      typeof navigator === 'undefined'
        ? null
        : (navigator.languages?.[0] ?? navigator.language);

    await this.setLocale(resolveSupportedLocale(storedLocale ?? browserLocale), false);
  }

  async setLocale(locale: SupportedLocale, persist = true): Promise<void> {
    const sequence = ++this.loadSequence;
    const translations = await this.loadTranslations(locale);

    if (sequence !== this.loadSequence) {
      return;
    }

    this.activeTranslations.set(translations);
    this.activeLocale.set(locale);
    this.document.documentElement.lang = locale;

    if (persist) {
      this.storeLocale(locale);
    }
  }

  translate(key: TranslationKey, parameters: TranslationParameters = {}): string {
    const value = this.activeTranslations()[key] ?? EN_TRANSLATIONS[key];

    return value.replace(/\{\{(\w+)\}\}/g, (match, parameter: string) => {
      const replacement = parameters[parameter];

      return replacement === undefined ? match : String(replacement);
    });
  }

  formatNumber(value: number, options?: Intl.NumberFormatOptions): string {
    return new Intl.NumberFormat(this.activeLocale(), options).format(value);
  }

  formatMoney(
    amountMinor: number | null,
    currencyCode: string | null,
    minorUnit: number | null,
  ): string {
    if (amountMinor === null || currencyCode === null || minorUnit === null) {
      return this.translate('common.priceNotProvided');
    }

    const amount = amountMinor / 10 ** minorUnit;

    try {
      return new Intl.NumberFormat(this.activeLocale(), {
        style: 'currency',
        currency: currencyCode,
        minimumFractionDigits: minorUnit,
        maximumFractionDigits: minorUnit,
      }).format(amount);
    } catch {
      return `${this.formatNumber(amount, {
        minimumFractionDigits: minorUnit,
        maximumFractionDigits: minorUnit,
      })} ${currencyCode}`;
    }
  }

  formatDate(
    value: Date | number | string,
    options?: Intl.DateTimeFormatOptions,
  ): string {
    return new Intl.DateTimeFormat(this.activeLocale(), options).format(new Date(value));
  }

  regionName(code: string, fallback: string): string {
    try {
      return (
        new Intl.DisplayNames([this.activeLocale()], { type: 'region' }).of(code) ??
        fallback
      );
    } catch {
      return fallback;
    }
  }

  currencyName(code: string, fallback: string): string {
    try {
      return (
        new Intl.DisplayNames([this.activeLocale()], { type: 'currency' }).of(code) ??
        fallback
      );
    } catch {
      return fallback;
    }
  }

  continentName(code: string, fallback: string): string {
    const keys: Readonly<Record<string, TranslationKey>> = {
      AF: 'market.continent.africa',
      AN: 'market.continent.antarctica',
      AS: 'market.continent.asia',
      EU: 'market.continent.europe',
      NA: 'market.continent.northAmerica',
      OC: 'market.continent.oceania',
      SA: 'market.continent.southAmerica',
    };
    const key = keys[code];

    return key === undefined ? fallback : this.translate(key);
  }

  private async loadTranslations(locale: SupportedLocale): Promise<TranslationDictionary> {
    switch (locale) {
      case 'de':
        return import('./locales/de').then((module) => module.DE_TRANSLATIONS);
      case 'es':
        return import('./locales/es').then((module) => module.ES_TRANSLATIONS);
      case 'fr':
        return import('./locales/fr').then((module) => module.FR_TRANSLATIONS);
      case 'sr-Latn':
        return import('./locales/sr-Latn').then((module) => module.SR_LATN_TRANSLATIONS);
      default:
        return EN_TRANSLATIONS;
    }
  }

  private readStoredLocale(): string | null {
    try {
      return globalThis.localStorage?.getItem(STORAGE_KEY) ?? null;
    } catch {
      return null;
    }
  }

  private storeLocale(locale: SupportedLocale): void {
    try {
      globalThis.localStorage?.setItem(STORAGE_KEY, locale);
    } catch {
      // A blocked storage API must not prevent language switching for the current page.
    }
  }
}
