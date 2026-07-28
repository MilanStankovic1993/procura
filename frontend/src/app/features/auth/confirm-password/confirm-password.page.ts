import { Component, inject, OnInit, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { finalize } from 'rxjs';

import { AuthService } from '../../../core/auth/auth.service';
import { I18nService } from '../../../core/i18n/i18n.service';
import { TranslatePipe } from '../../../core/i18n/translate.pipe';
import { LanguageSwitcherComponent } from '../../../shared/language-switcher/language-switcher.component';
import { authFormError } from '../auth-error';
import { safeReturnUrl } from '../auth-navigation';

@Component({
  selector: 'app-confirm-password-page',
  imports: [LanguageSwitcherComponent, ReactiveFormsModule, RouterLink, TranslatePipe],
  templateUrl: './confirm-password.page.html',
  styleUrl: '../auth-page.scss',
})
export class ConfirmPasswordPage implements OnInit {
  private readonly auth = inject(AuthService);
  private readonly i18n = inject(I18nService);
  private readonly formBuilder = inject(FormBuilder);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);

  protected readonly isChecking = signal(true);
  protected readonly isSubmitting = signal(false);
  protected readonly generalError = signal<string | null>(null);
  protected readonly fieldErrors = signal<Record<string, string[]>>({});
  protected readonly form = this.formBuilder.nonNullable.group({
    password: ['', [Validators.required]],
  });

  ngOnInit(): void {
    this.auth
      .passwordConfirmationStatus()
      .pipe(finalize(() => this.isChecking.set(false)))
      .subscribe({
        next: (status) => {
          if (status.confirmed) {
            this.continueToDestination();
          }
        },
        error: () =>
          this.generalError.set(
            this.i18n.translate('confirm.statusError'),
          ),
      });
  }

  protected submit(): void {
    this.generalError.set(null);
    this.fieldErrors.set({});

    if (this.form.invalid) {
      this.form.markAllAsTouched();

      return;
    }

    this.isSubmitting.set(true);
    this.auth
      .confirmPassword(this.form.controls.password.value)
      .pipe(finalize(() => this.isSubmitting.set(false)))
      .subscribe({
        next: () => this.continueToDestination(),
        error: (error: unknown) => {
          const formError = authFormError(
            error,
            this.i18n.translate('confirm.error'),
            this.i18n,
          );
          this.fieldErrors.set(formError.fields);
          this.generalError.set(formError.message);
        },
      });
  }

  private continueToDestination(): void {
    void this.router.navigateByUrl(
      safeReturnUrl(this.route.snapshot.queryParamMap.get('returnUrl')),
    );
  }
}
