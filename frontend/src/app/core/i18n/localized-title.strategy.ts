import { effect, inject, Injectable } from '@angular/core';
import { Title } from '@angular/platform-browser';
import { ActivatedRouteSnapshot, RouterStateSnapshot, TitleStrategy } from '@angular/router';

import { I18nService } from './i18n.service';
import { TranslationKey } from './locales/en';

@Injectable()
export class LocalizedTitleStrategy extends TitleStrategy {
  private readonly title = inject(Title);
  private readonly i18n = inject(I18nService);
  private activeTitleKey: TranslationKey = 'route.landing';

  constructor() {
    super();

    effect(() => {
      this.i18n.locale();
      this.applyTitle();
    });
  }

  override updateTitle(snapshot: RouterStateSnapshot): void {
    let route: ActivatedRouteSnapshot | null = snapshot.root;

    while (route !== null) {
      const titleKey = route.data['titleKey'];

      if (typeof titleKey === 'string') {
        this.activeTitleKey = titleKey as TranslationKey;
      }

      route = route.firstChild;
    }

    this.applyTitle();
  }

  private applyTitle(): void {
    this.title.setTitle(`${this.i18n.translate(this.activeTitleKey)} — Procura`);
  }
}
