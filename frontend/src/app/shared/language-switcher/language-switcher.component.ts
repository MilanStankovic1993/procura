import { Component, inject, signal } from '@angular/core';
import { finalize } from 'rxjs';

import { AuthService } from '../../core/auth/auth.service';
import { resolveSupportedLocale } from '../../core/i18n/i18n.models';
import { I18nService } from '../../core/i18n/i18n.service';
import { TranslatePipe } from '../../core/i18n/translate.pipe';

@Component({
  selector: 'app-language-switcher',
  imports: [TranslatePipe],
  templateUrl: './language-switcher.component.html',
  styleUrl: './language-switcher.component.scss',
})
export class LanguageSwitcherComponent {
  private readonly auth = inject(AuthService);
  private readonly i18n = inject(I18nService);

  protected readonly locale = this.i18n.locale;
  protected readonly locales = this.i18n.locales;
  protected readonly saving = signal(false);
  protected readonly failed = signal(false);

  protected changeLocale(event: Event): void {
    const locale = resolveSupportedLocale((event.target as HTMLSelectElement).value);
    this.failed.set(false);

    if (this.auth.user() === null) {
      this.saving.set(true);
      void this.i18n
        .setLocale(locale)
        .catch(() => this.failed.set(true))
        .finally(() => this.saving.set(false));

      return;
    }

    this.saving.set(true);
    this.auth
      .updatePreferredLocale(locale)
      .pipe(finalize(() => this.saving.set(false)))
      .subscribe({
        error: () => this.failed.set(true),
      });
  }
}
