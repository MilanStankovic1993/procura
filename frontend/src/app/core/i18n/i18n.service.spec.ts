import { TestBed } from '@angular/core/testing';

import { resolveSupportedLocale } from './i18n.models';
import { I18nService } from './i18n.service';

describe('I18nService', () => {
  let service: I18nService;

  beforeEach(() => {
    globalThis.localStorage.clear();
    TestBed.configureTestingModule({});
    service = TestBed.inject(I18nService);
  });

  afterEach(() => {
    globalThis.localStorage.clear();
  });

  it('resolves supported BCP 47 browser variants without confusing locale and market', () => {
    expect(resolveSupportedLocale('de-AT')).toBe('de');
    expect(resolveSupportedLocale('fr-CA')).toBe('fr');
    expect(resolveSupportedLocale('sr-Cyrl-RS')).toBe('sr-Latn');
    expect(resolveSupportedLocale('it-IT')).toBe('en');
  });

  it('lazy loads every supported catalog and updates document language and storage', async () => {
    const expectedTitles = {
      de: 'Willkommen zurück.',
      es: 'Te damos la bienvenida.',
      fr: 'Bon retour.',
      'sr-Latn': 'Dobro došao nazad.',
    } as const;

    for (const [locale, title] of Object.entries(expectedTitles)) {
      await service.setLocale(locale as keyof typeof expectedTitles);

      expect(service.locale()).toBe(locale);
      expect(service.translate('login.title')).toBe(title);
      expect(document.documentElement.lang).toBe(locale);
      expect(globalThis.localStorage.getItem('procura.preferred-locale')).toBe(locale);
    }
  });

  it('interpolates parameters while retaining English as the deterministic default', async () => {
    expect(service.translate('reset.description', { email: 'qa@procura.test' })).toContain(
      'qa@procura.test',
    );

    globalThis.localStorage.setItem('procura.preferred-locale', 'de');
    await service.initialize();

    expect(service.locale()).toBe('de');
    expect(service.translate('common.signIn')).toBe('Anmelden');
  });

  it('loads translated domain screens instead of silently falling back to English', async () => {
    const expectedListingTitles = {
      de: 'Quellangebote',
      es: 'Anuncios de origen',
      fr: 'Annonces sources',
      'sr-Latn': 'Izvorni oglasi',
    } as const;

    for (const [locale, title] of Object.entries(expectedListingTitles)) {
      await service.setLocale(locale as keyof typeof expectedListingTitles);

      expect(service.translate('listingList.title')).toBe(title);
      expect(service.translate('analysisDetail.productMatch')).not.toBe(
        'Product match',
      );
      expect(service.translate('buyerDecision.title')).not.toBe(
        'Decision status',
      );
    }
  });
});
