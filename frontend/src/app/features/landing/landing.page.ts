import { Component } from '@angular/core';
import { RouterLink } from '@angular/router';

import { TranslationKey } from '../../core/i18n/locales/en';
import { TranslatePipe } from '../../core/i18n/translate.pipe';
import { LanguageSwitcherComponent } from '../../shared/language-switcher/language-switcher.component';

@Component({
  selector: 'app-landing-page',
  imports: [LanguageSwitcherComponent, RouterLink, TranslatePipe],
  templateUrl: './landing.page.html',
  styleUrl: './landing.page.scss',
})
export class LandingPage {
  protected readonly capabilities = [
    {
      label: 'landing.buyLabel',
      title: 'landing.buyTitle',
      description: 'landing.buyDescription',
    },
    {
      label: 'landing.sellLabel',
      title: 'landing.sellTitle',
      description: 'landing.sellDescription',
    },
    {
      label: 'landing.globalLabel',
      title: 'landing.globalTitle',
      description: 'landing.globalDescription',
    },
  ] satisfies readonly {
    readonly label: TranslationKey;
    readonly title: TranslationKey;
    readonly description: TranslationKey;
  }[];
}
