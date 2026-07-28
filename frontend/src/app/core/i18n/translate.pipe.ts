import { inject, Pipe, PipeTransform } from '@angular/core';

import { I18nService } from './i18n.service';
import { TranslationKey } from './locales/en';

@Pipe({
  name: 't',
  pure: false,
})
export class TranslatePipe implements PipeTransform {
  private readonly i18n = inject(I18nService);

  transform(
    key: TranslationKey,
    parameters?: Readonly<Record<string, string | number>>,
  ): string {
    return this.i18n.translate(key, parameters);
  }
}
