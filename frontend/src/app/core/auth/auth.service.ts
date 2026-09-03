import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { computed, inject, Injectable, signal } from '@angular/core';
import { catchError, finalize, map, Observable, of, switchMap, tap, throwError } from 'rxjs';

import { ApiEnvelope } from '../api/api.models';
import { SupportedLocale } from '../i18n/i18n.models';
import { I18nService } from '../i18n/i18n.service';
import { OrganizationSummary } from '../organizations/organization.models';
import {
  AuthenticatedUser,
  LoginCredentials,
  MessageResponse,
  PasswordConfirmationStatus,
  PasswordResetData,
  PreferredLocaleResponse,
  RegistrationData,
} from './auth.models';

export type AuthState =
  | 'unknown'
  | 'authenticated'
  | 'unverified'
  | 'anonymous'
  | 'organization-unavailable';

@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly http = inject(HttpClient);
  private readonly i18n = inject(I18nService);
  private readonly currentUser = signal<AuthenticatedUser | null>(null);
  private readonly currentState = signal<AuthState>('unknown');
  private readonly currentContextError = signal<string | null>(null);

  readonly user = this.currentUser.asReadonly();
  readonly state = this.currentState.asReadonly();
  readonly contextError = this.currentContextError.asReadonly();
  readonly isAuthenticated = computed(
    () => this.currentState() === 'authenticated' || this.currentState() === 'unverified',
  );

  ensureAuthenticated(): Observable<boolean> {
    if (this.currentState() !== 'unknown') {
      return of(this.isAuthenticated());
    }

    return this.loadCurrentUser().pipe(map((user) => user !== null));
  }

  loadCurrentUser(): Observable<AuthenticatedUser | null> {
    return this.http.get<ApiEnvelope<AuthenticatedUser>>('/api/v1/me').pipe(
      map((response) => response.data),
      tap((user) => {
        this.currentUser.set(user);
        void this.i18n.setLocale(user.preferred_locale);
        this.currentState.set(user.email_verified_at === null ? 'unverified' : 'authenticated');
        this.currentContextError.set(null);
      }),
      catchError((error: unknown) => {
        if (error instanceof HttpErrorResponse && error.status === 401) {
          this.clearSession();

          return of(null);
        }

        if (error instanceof HttpErrorResponse && error.status === 409) {
          const body = error.error as { message?: string; code?: string } | null;

          if (body?.code === 'organization_context_unavailable') {
            this.currentUser.set(null);
            this.currentState.set('organization-unavailable');
            this.currentContextError.set(
              body.message ?? 'No organization workspace is available for this account.',
            );

            return of(null);
          }
        }

        return throwError(() => error);
      }),
    );
  }

  login(credentials: LoginCredentials): Observable<AuthenticatedUser> {
    return this.csrfCookie().pipe(
      switchMap(() => this.http.post<void>('/api/v1/auth/login', credentials)),
      switchMap(() => this.loadCurrentUser()),
      map((user) => this.requireCurrentUser(user, 'The session was not established after login.')),
    );
  }

  register(data: RegistrationData): Observable<AuthenticatedUser> {
    return this.csrfCookie().pipe(
      switchMap(() => this.http.post<void>('/api/v1/auth/register', data)),
      switchMap(() => this.loadCurrentUser()),
      map((user) =>
        this.requireCurrentUser(user, 'The session was not established after registration.'),
      ),
    );
  }

  requestPasswordReset(email: string): Observable<MessageResponse> {
    return this.csrfCookie().pipe(
      switchMap(() =>
        this.http.post<MessageResponse>('/api/v1/auth/forgot-password', { email }),
      ),
    );
  }

  resetPassword(data: PasswordResetData): Observable<MessageResponse> {
    return this.csrfCookie().pipe(
      switchMap(() =>
        this.http.post<MessageResponse>('/api/v1/auth/reset-password', data),
      ),
    );
  }

  resendEmailVerification(): Observable<void> {
    return this.csrfCookie().pipe(
      switchMap(() =>
        this.http.post<void>('/api/v1/auth/email/verification-notification', {}),
      ),
    );
  }

  verifyEmail(verificationPath: string): Observable<void> {
    if (!this.isTrustedVerificationPath(verificationPath)) {
      return throwError(() => new Error('The email verification link is invalid.'));
    }

    return this.http.get<void>(verificationPath);
  }

  passwordConfirmationStatus(): Observable<PasswordConfirmationStatus> {
    return this.http.get<PasswordConfirmationStatus>('/api/v1/auth/password-confirmation');
  }

  confirmPassword(password: string): Observable<void> {
    return this.csrfCookie().pipe(
      switchMap(() =>
        this.http.post<void>('/api/v1/auth/confirm-password', { password }),
      ),
    );
  }

  logout(): Observable<void> {
    return this.csrfCookie().pipe(
      switchMap(() => this.http.post<void>('/api/v1/auth/logout', {})),
      finalize(() => this.clearSession()),
    );
  }

  updatePreferredLocale(locale: SupportedLocale): Observable<SupportedLocale> {
    return this.http
      .patch<ApiEnvelope<PreferredLocaleResponse>>('/api/v1/me/preferences', {
        preferred_locale: locale,
      })
      .pipe(
        map((response) => response.data.preferred_locale),
        tap((preferredLocale) => {
          const user = this.currentUser();

          if (user !== null) {
            this.currentUser.set({
              ...user,
              preferred_locale: preferredLocale,
            });
          }

          void this.i18n.setLocale(preferredLocale);
        }),
      );
  }

  setCurrentOrganization(organization: OrganizationSummary): void {
    const user = this.currentUser();

    if (user === null) {
      return;
    }

    this.currentUser.set({
      ...user,
      current_organization: organization,
    });
  }

  private csrfCookie(): Observable<void> {
    return this.http.get<void>('/sanctum/csrf-cookie');
  }

  private requireCurrentUser(
    user: AuthenticatedUser | null,
    errorMessage: string,
  ): AuthenticatedUser {
    if (user === null) {
      throw new Error(errorMessage);
    }

    return user;
  }

  private isTrustedVerificationPath(verificationPath: string): boolean {
    if (!verificationPath.startsWith('/') || verificationPath.startsWith('//')) {
      return false;
    }

    try {
      const url = new URL(verificationPath, 'https://procura.invalid');

      return (
        /^\/api\/v1\/auth\/email\/verify\/\d+\/[a-f0-9]{40}$/.test(url.pathname) &&
        url.searchParams.has('expires') &&
        url.searchParams.has('signature')
      );
    } catch {
      return false;
    }
  }

  private clearSession(): void {
    this.currentUser.set(null);
    this.currentState.set('anonymous');
    this.currentContextError.set(null);
  }
}
