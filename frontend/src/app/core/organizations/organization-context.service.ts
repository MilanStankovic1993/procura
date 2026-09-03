import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { computed, inject, Injectable, signal } from '@angular/core';
import { catchError, finalize, map, Observable, of, tap, throwError } from 'rxjs';

import { ApiEnvelope } from '../api/api.models';
import { OrganizationSummary } from './organization.models';

type OrganizationContextState = 'idle' | 'loading' | 'ready' | 'error';

@Injectable({ providedIn: 'root' })
export class OrganizationContextService {
  private readonly http = inject(HttpClient);
  private readonly organizationList = signal<readonly OrganizationSummary[]>([]);
  private readonly currentState = signal<OrganizationContextState>('idle');
  private readonly currentError = signal<string | null>(null);
  private readonly currentSwitch = signal<string | null>(null);

  readonly organizations = this.organizationList.asReadonly();
  readonly state = this.currentState.asReadonly();
  readonly error = this.currentError.asReadonly();
  readonly switchingOrganizationId = this.currentSwitch.asReadonly();
  readonly activeOrganization = computed(
    () => this.organizationList().find((organization) => organization.is_active) ?? null,
  );

  hydrate(organization: OrganizationSummary | null): void {
    if (organization === null || this.organizationList().length > 0) {
      return;
    }

    this.organizationList.set([{ ...organization, is_active: true }]);
  }

  load(): Observable<readonly OrganizationSummary[]> {
    this.currentState.set('loading');
    this.currentError.set(null);

    return this.http
      .get<ApiEnvelope<readonly OrganizationSummary[]>>('/api/v1/organizations')
      .pipe(
        map((response) => response.data),
        tap((organizations) => {
          this.organizationList.set(organizations);
          this.currentState.set('ready');
        }),
        catchError((error: unknown) => {
          this.currentState.set('error');
          this.currentError.set(this.messageFor(error));

          return throwError(() => error);
        }),
      );
  }

  activate(organizationId: string): Observable<OrganizationSummary> {
    const active = this.activeOrganization();

    if (active?.id === organizationId) {
      return of(active);
    }

    this.currentSwitch.set(organizationId);
    this.currentError.set(null);

    return this.http
      .put<ApiEnvelope<OrganizationSummary>>(
        `/api/v1/organizations/${encodeURIComponent(organizationId)}/activate`,
        {},
      )
      .pipe(
        map((response) => response.data),
        tap((activated) => {
          const existing = this.organizationList();
          const updated = existing.map((organization) => ({
            ...organization,
            is_active: organization.id === activated.id,
          }));

          this.organizationList.set(
            updated.some((organization) => organization.id === activated.id)
              ? updated
              : [...updated, activated],
          );
          this.currentState.set('ready');
        }),
        catchError((error: unknown) => {
          this.currentError.set(this.messageFor(error));

          return throwError(() => error);
        }),
        finalize(() => this.currentSwitch.set(null)),
      );
  }

  create(name: string): Observable<OrganizationSummary> {
    return this.http
      .post<ApiEnvelope<OrganizationSummary>>('/api/v1/organizations', { name })
      .pipe(
        map((response) => response.data),
        tap((created) => {
          this.organizationList.set([
            ...this.organizationList().map((organization) => ({
              ...organization,
              is_active: false,
            })),
            created,
          ]);
          this.currentState.set('ready');
        }),
      );
  }

  updateActive(organization: OrganizationSummary): void {
    this.organizationList.update((organizations) =>
      organizations.map((item) => (item.id === organization.id ? organization : item)),
    );
  }

  reset(): void {
    this.organizationList.set([]);
    this.currentState.set('idle');
    this.currentError.set(null);
    this.currentSwitch.set(null);
  }

  private messageFor(error: unknown): string {
    if (error instanceof HttpErrorResponse && error.status === 404) {
      return 'That workspace is no longer available to your account.';
    }

    return 'We could not update your workspace. Please try again.';
  }
}
