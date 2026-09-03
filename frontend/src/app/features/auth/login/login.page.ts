import { Component, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { finalize } from 'rxjs';

import { AuthService } from '../../../core/auth/auth.service';
import { I18nService } from '../../../core/i18n/i18n.service';
import { TranslatePipe } from '../../../core/i18n/translate.pipe';
import { LanguageSwitcherComponent } from '../../../shared/language-switcher/language-switcher.component';
import { authFormError } from '../auth-error';

@Component({
  selector: 'app-login-page',
  imports: [LanguageSwitcherComponent, ReactiveFormsModule, RouterLink, TranslatePipe],
  templateUrl: './login.page.html',
  styleUrl: '../auth-page.scss',
})
export class LoginPage {
  private readonly auth = inject(AuthService);
  private readonly i18n = inject(I18nService);
  private readonly formBuilder = inject(FormBuilder);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);

  protected readonly isSubmitting = signal(false);
  protected readonly generalError = signal<string | null>(null);
  protected readonly fieldErrors = signal<Record<string, string[]>>({});
  protected readonly form = this.formBuilder.nonNullable.group({
    email: ['', [Validators.required, Validators.email]],
    password: ['', [Validators.required]],
    remember: [false],
  });

  protected submit(): void {
    this.generalError.set(null);
    this.fieldErrors.set({});

    if (this.form.invalid) {
      this.form.markAllAsTouched();

      return;
    }

    this.isSubmitting.set(true);
    this.auth
      .login(this.form.getRawValue())
      .pipe(finalize(() => this.isSubmitting.set(false)))
      .subscribe({
        next: () => {
          const returnUrl = this.route.snapshot.queryParamMap.get('returnUrl');
          void this.router.navigateByUrl(
            returnUrl?.startsWith('/') && !returnUrl.startsWith('//') ? returnUrl : '/app',
          );
        },
        error: (error: unknown) => {
          if (this.auth.state() === 'organization-unavailable') {
            void this.router.navigateByUrl('/workspace-unavailable');

            return;
          }

          this.handleError(error);
        },
      });
  }

  private handleError(error: unknown): void {
    const formError = authFormError(
      error,
      this.i18n.translate('login.error'),
      this.i18n,
    );
    this.fieldErrors.set(formError.fields);
    this.generalError.set(formError.message);
  }
}
