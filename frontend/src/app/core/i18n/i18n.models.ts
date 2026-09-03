export const SUPPORTED_LOCALES = ['en', 'de', 'es', 'fr', 'sr-Latn'] as const;

export type SupportedLocale = (typeof SUPPORTED_LOCALES)[number];

export interface LocaleOption {
  readonly code: SupportedLocale;
  readonly label: string;
}

export const LOCALE_OPTIONS: readonly LocaleOption[] = [
  { code: 'en', label: 'English' },
  { code: 'de', label: 'Deutsch' },
  { code: 'es', label: 'Español' },
  { code: 'fr', label: 'Français' },
  { code: 'sr-Latn', label: 'Srpski' },
];

export function resolveSupportedLocale(value: string | null | undefined): SupportedLocale {
  if (value === 'sr' || value?.toLowerCase().startsWith('sr-')) {
    return 'sr-Latn';
  }

  const normalized = value?.toLowerCase().split('-')[0];

  return SUPPORTED_LOCALES.find((locale) => locale === value || locale === normalized) ?? 'en';
}
