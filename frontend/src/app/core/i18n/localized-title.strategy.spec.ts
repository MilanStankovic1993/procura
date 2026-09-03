import { TestBed } from '@angular/core/testing';
import { Title } from '@angular/platform-browser';
import { RouterStateSnapshot } from '@angular/router';

import { I18nService } from './i18n.service';
import { LocalizedTitleStrategy } from './localized-title.strategy';

describe('LocalizedTitleStrategy', () => {
  let strategy: LocalizedTitleStrategy;
  let title: Title;
  let i18n: I18nService;

  beforeEach(() => {
    globalThis.localStorage.clear();
    TestBed.configureTestingModule({
      providers: [LocalizedTitleStrategy],
    });
    strategy = TestBed.inject(LocalizedTitleStrategy);
    title = TestBed.inject(Title);
    i18n = TestBed.inject(I18nService);
  });

  afterEach(() => {
    globalThis.localStorage.clear();
  });

  it('uses the deepest declared route title and follows locale changes', async () => {
    const snapshot = {
      root: {
        data: { titleKey: 'route.landing' },
        firstChild: {
          data: { titleKey: 'route.subscription' },
          firstChild: {
            data: {},
            firstChild: null,
          },
        },
      },
    } as unknown as RouterStateSnapshot;

    strategy.updateTitle(snapshot);
    expect(title.getTitle()).toBe('Plan & usage — Procura');

    await i18n.setLocale('sr-Latn');
    strategy.updateTitle(snapshot);

    expect(title.getTitle()).toBe('Paket i potrošnja — Procura');
  });
});
