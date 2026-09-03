import { Component, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { finalize } from 'rxjs';

import { AuthService } from '../../../core/auth/auth.service';
import { I18nService } from '../../../core/i18n/i18n.service';
import { TranslatePipe } from '../../../core/i18n/translate.pipe';
import { LanguageSwitcherComponent } from '../../../shared/language-switcher/language-switcher.component';
import { authFormError } from '../auth-error';

@Component({
  selector: 'app-forgot-password-page',
  imports: [LanguageSwitcherComponent, ReactiveFormsModule, RouterLink, TranslatePipe],
  templateUrl: './forgot-password.page.html',
  styleUrl: '../auth-page.scss',
})
export class ForgotPasswordPage {
  private readonly auth = inject(AuthService);
  private readonly i18n = inject(I18nService);
  private readonly formBuilder = inject(FormBuilder);

  protected readonly isSubmitting = signal(false);
  protected readonly generalError = signal<string | null>(null);
  protected readonly successMessage = signal<string | null>(null);
  protected readonly fieldErrors = signal<Record<string, string[]>>({});
  protected readonly form = this.formBuilder.nonNullable.group({
    email: ['', [Validators.required, Validators.email]],
  });

  protected submit(): void {
    this.generalError.set(null);
    this.successMessage.set(null);
    this.fieldErrors.set({});

    if (this.form.invalid) {
      this.form.markAllAsTouched();

      return;
    }

    this.isSubmitting.set(true);
    this.auth
      .requestPasswordReset(this.form.controls.email.value)
      .pipe(finalize(() => this.isSubmitting.set(false)))
      .subscribe({
        next: (response) => this.successMessage.set(response.message),
        error: (error: unknown) => {
          const formError = authFormError(
            error,
            this.i18n.translate('forgot.error'),
            this.i18n,
          );
          this.fieldErrors.set(formError.fields);
          this.generalError.set(formError.message);
        },
      });
  }
}
