import { Component, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
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
import { safeReturnUrl } from '../auth-navigation';

@Component({
  selector: 'app-register-page',
  imports: [LanguageSwitcherComponent, ReactiveFormsModule, RouterLink, TranslatePipe],
  templateUrl: './register.page.html',
  styleUrl: '../auth-page.scss',
})
export class RegisterPage {
  private readonly auth = inject(AuthService);
  private readonly i18n = inject(I18nService);
  private readonly formBuilder = inject(FormBuilder);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);

  protected readonly isSubmitting = signal(false);
  protected readonly generalError = signal<string | null>(null);
  protected readonly fieldErrors = signal<Record<string, string[]>>({});
  protected readonly form = this.formBuilder.nonNullable.group(
    {
      name: ['', [Validators.required, Validators.maxLength(255)]],
      email: ['', [Validators.required, Validators.email, Validators.maxLength(255)]],
      password: ['', securePasswordValidators],
      password_confirmation: ['', [Validators.required]],
    },
    { validators: passwordsMatchValidator },
  );

  protected submit(): void {
    this.generalError.set(null);
    this.fieldErrors.set({});

    if (this.form.invalid) {
      this.form.markAllAsTouched();

      return;
    }

    this.isSubmitting.set(true);
    this.auth
      .register({
        ...this.form.getRawValue(),
        preferred_locale: this.i18n.locale(),
      })
      .pipe(finalize(() => this.isSubmitting.set(false)))
      .subscribe({
        next: () => {
          void this.router.navigate(['/verify-email'], {
            queryParams: {
              returnUrl: safeReturnUrl(this.route.snapshot.queryParamMap.get('returnUrl')),
            },
          });
        },
        error: (error: unknown) => {
          const formError = authFormError(
            error,
            this.i18n.translate('register.error'),
            this.i18n,
          );
          this.fieldErrors.set(formError.fields);
          this.generalError.set(formError.message);
        },
      });
  }
}
