import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { firstValueFrom } from 'rxjs';

import { AuthenticatedUser, RegistrationData } from './auth.models';
import { AuthService } from './auth.service';

describe('AuthService', () => {
  let service: AuthService;
  let http: HttpTestingController;

  const user: AuthenticatedUser = {
    id: 42,
    name: 'Procura User',
    email: 'user@example.com',
    preferred_locale: 'en',
    email_verified_at: null,
    current_organization: {
      id: '01KPERSONAL',
      name: 'Procura User workspace',
      type: 'personal',
      role: 'owner',
      capabilities: [
        'organization.update',
        'organization.members.view',
        'organization.members.invite',
        'organization.members.manage',
        'organization.ownership.transfer',
        'organization.audit.view',
      ],
      joined_at: '2026-07-25T08:00:00.000000Z',
      is_active: true,
      created_at: '2026-07-25T08:00:00.000000Z',
      updated_at: '2026-07-25T08:00:00.000000Z',
    },
    created_at: '2026-07-25T08:00:00.000000Z',
    updated_at: '2026-07-25T08:00:00.000000Z',
  };

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });

    service = TestBed.inject(AuthService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('keeps an unverified session authenticated while exposing its restricted state', async () => {
    const result = firstValueFrom(service.loadCurrentUser());
    const request = http.expectOne('/api/v1/me');

    request.flush({ data: user });

    expect(await result).toEqual(user);
    expect(service.isAuthenticated()).toBe(true);
    expect(service.state()).toBe('unverified');
  });

  it('registers only after obtaining a CSRF cookie and then loads the session user', async () => {
    const registration: RegistrationData = {
      name: 'Procura User',
      email: 'user@example.com',
      password: 'SecurePass123!',
      password_confirmation: 'SecurePass123!',
      preferred_locale: 'en',
    };
    const result = firstValueFrom(service.register(registration));

    const csrf = http.expectOne('/sanctum/csrf-cookie');
    expect(csrf.request.method).toBe('GET');
    csrf.flush(null);

    const register = http.expectOne('/api/v1/auth/register');
    expect(register.request.method).toBe('POST');
    expect(register.request.body).toEqual(registration);
    register.flush(null, { status: 201, statusText: 'Created' });

    const currentUser = http.expectOne('/api/v1/me');
    currentUser.flush({ data: user });

    expect(await result).toEqual(user);
    expect(service.state()).toBe('unverified');
  });

  it('does not issue a request for an off-origin email verification target', async () => {
    await expect(
      firstValueFrom(
        service.verifyEmail(
          'https://attacker.example/api/v1/auth/email/verify/42/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa?expires=1&signature=x',
        ),
      ),
    ).rejects.toThrow('The email verification link is invalid.');
  });

  it('sends a structurally valid relative verification path to the backend', async () => {
    const path =
      '/api/v1/auth/email/verify/42/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa?expires=1784966400&signature=signed-value';
    const result = firstValueFrom(service.verifyEmail(path));
    const request = http.expectOne(path);

    expect(request.request.method).toBe('GET');
    request.flush(null, { status: 204, statusText: 'No Content' });

    await result;
  });

  it('refreshes CSRF protection before confirming the current password', async () => {
    const result = firstValueFrom(service.confirmPassword('SecurePass123!'));

    http.expectOne('/sanctum/csrf-cookie').flush(null);
    const confirmation = http.expectOne('/api/v1/auth/confirm-password');
    expect(confirmation.request.method).toBe('POST');
    expect(confirmation.request.body).toEqual({ password: 'SecurePass123!' });
    confirmation.flush(null, { status: 201, statusText: 'Created' });

    await result;
  });

  it('persists an authenticated locale preference without replacing organization context', async () => {
    const initialLoad = firstValueFrom(service.loadCurrentUser());
    http.expectOne('/api/v1/me').flush({ data: user });
    await initialLoad;

    const result = firstValueFrom(service.updatePreferredLocale('sr-Latn'));
    const request = http.expectOne('/api/v1/me/preferences');

    expect(request.request.method).toBe('PATCH');
    expect(request.request.body).toEqual({ preferred_locale: 'sr-Latn' });
    request.flush({ data: { preferred_locale: 'sr-Latn' } });

    expect(await result).toBe('sr-Latn');
    expect(service.user()?.preferred_locale).toBe('sr-Latn');
    expect(service.user()?.current_organization.id).toBe('01KPERSONAL');
  });
});
