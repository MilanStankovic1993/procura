import { Component, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { finalize } from 'rxjs';

import { AuthService } from '../../../core/auth/auth.service';
import { I18nService } from '../../../core/i18n/i18n.service';
import { TranslatePipe } from '../../../core/i18n/translate.pipe';
import { LanguageSwitcherComponent } from '../../../shared/language-switcher/language-switcher.component';
import { authFormError } from '../auth-error';
import {
  passwordsMatchValidator,
  securePasswordValidators,
} from '../auth-form.validators';

@Component({
  selector: 'app-reset-password-page',
  imports: [LanguageSwitcherComponent, ReactiveFormsModule, RouterLink, TranslatePipe],
  templateUrl: './reset-password.page.html',
  styleUrl: '../auth-page.scss',
})
export class ResetPasswordPage {
  private readonly auth = inject(AuthService);
  private readonly i18n = inject(I18nService);
  private readonly formBuilder = inject(FormBuilder);
  private readonly route = inject(ActivatedRoute);
  private readonly token = this.route.snapshot.queryParamMap.get('token') ?? '';

  protected readonly email = this.route.snapshot.queryParamMap.get('email') ?? '';
  protected readonly invalidLink = this.token === '' || this.email === '';
  protected readonly isSubmitting = signal(false);
  protected readonly generalError = signal<string | null>(null);
  protected readonly successMessage = signal<string | null>(null);
  protected readonly fieldErrors = signal<Record<string, string[]>>({});
  protected readonly form = this.formBuilder.nonNullable.group(
    {
      password: ['', securePasswordValidators],
      password_confirmation: ['', [Validators.required]],
    },
    { validators: passwordsMatchValidator },
  );

  protected submit(): void {
    this.generalError.set(null);
    this.fieldErrors.set({});

    if (this.invalidLink) {
      this.generalError.set(this.i18n.translate('reset.invalidError'));

      return;
    }

    if (this.form.invalid) {
      this.form.markAllAsTouched();

      return;
    }

    this.isSubmitting.set(true);
    this.auth
      .resetPassword({
        token: this.token,
        email: this.email,
        ...this.form.getRawValue(),
      })
      .pipe(finalize(() => this.isSubmitting.set(false)))
      .subscribe({
        next: (response) => {
          this.successMessage.set(response.message);
          this.form.disable();
        },
        error: (error: unknown) => {
          const formError = authFormError(
            error,
            this.i18n.translate('reset.expiredError'),
            this.i18n,
          );
          this.fieldErrors.set(formError.fields);
          this.generalError.set(formError.fields['email']?.[0] ?? formError.message);
        },
      });
  }
}
