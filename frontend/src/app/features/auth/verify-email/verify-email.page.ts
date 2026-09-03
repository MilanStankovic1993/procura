import { HttpErrorResponse } from '@angular/common/http';
import { Component, inject, OnInit, signal } from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { finalize, switchMap } from 'rxjs';

import { AuthService } from '../../../core/auth/auth.service';
import { I18nService } from '../../../core/i18n/i18n.service';
import { TranslatePipe } from '../../../core/i18n/translate.pipe';
import { LanguageSwitcherComponent } from '../../../shared/language-switcher/language-switcher.component';
import { safeReturnUrl } from '../auth-navigation';

@Component({
  selector: 'app-verify-email-page',
  imports: [LanguageSwitcherComponent, RouterLink, TranslatePipe],
  templateUrl: './verify-email.page.html',
  styleUrl: '../auth-page.scss',
})
export class VerifyEmailPage implements OnInit {
  private readonly auth = inject(AuthService);
  private readonly i18n = inject(I18nService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);

  protected readonly email = this.auth.user()?.email ?? '';
  protected readonly isVerifying = signal(false);
  protected readonly isResending = signal(false);
  protected readonly isSigningOut = signal(false);
  protected readonly verified = signal(this.auth.state() === 'authenticated');
  protected readonly generalError = signal<string | null>(null);
  protected readonly successMessage = signal<string | null>(null);

  ngOnInit(): void {
    const verificationPath = this.route.snapshot.queryParamMap.get('verification');

    if (verificationPath === null || this.verified()) {
      return;
    }

    this.isVerifying.set(true);
    this.auth
      .verifyEmail(verificationPath)
      .pipe(
        switchMap(() => this.auth.loadCurrentUser()),
        finalize(() => this.isVerifying.set(false)),
      )
      .subscribe({
        next: (user) => {
          if (user?.email_verified_at === null || user === null) {
            this.generalError.set(this.i18n.translate('verify.stateError'));

            return;
          }

          this.verified.set(true);
          this.successMessage.set(this.i18n.translate('verify.success'));
        },
        error: (error: unknown) => this.handleVerificationError(error),
      });
  }

  protected resend(): void {
    this.generalError.set(null);
    this.successMessage.set(null);
    this.isResending.set(true);
    this.auth
      .resendEmailVerification()
      .pipe(finalize(() => this.isResending.set(false)))
      .subscribe({
        next: () =>
          this.successMessage.set(this.i18n.translate('verify.sent')),
        error: (error: unknown) => {
          this.generalError.set(
            error instanceof HttpErrorResponse && error.status === 429
              ? this.i18n.translate('verify.tooManyRequests')
              : this.i18n.translate('verify.sendError'),
          );
        },
      });
  }

  protected continueToWorkspace(): void {
    void this.router.navigateByUrl(
      safeReturnUrl(this.route.snapshot.queryParamMap.get('returnUrl')),
    );
  }

  protected signOut(): void {
    this.isSigningOut.set(true);
    this.auth
      .logout()
      .pipe(finalize(() => this.isSigningOut.set(false)))
      .subscribe({
        next: () => void this.router.navigateByUrl('/login'),
        error: () => void this.router.navigateByUrl('/login'),
      });
  }

  private handleVerificationError(error: unknown): void {
    if (error instanceof HttpErrorResponse) {
      if (error.status === 403) {
        this.generalError.set(
          this.i18n.translate('verify.invalidLink'),
        );

        return;
      }

      if (error.status === 429) {
        this.generalError.set(this.i18n.translate('verify.tooManyAttempts'));

        return;
      }
    }

    this.generalError.set(
      error instanceof Error
        ? error.message
        : this.i18n.translate('verify.error'),
    );
  }
}
