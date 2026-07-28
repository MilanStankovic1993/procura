import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { firstValueFrom } from 'rxjs';

import { OrganizationSummary } from './organization.models';
import { OrganizationContextService } from './organization-context.service';

describe('OrganizationContextService', () => {
  let service: OrganizationContextService;
  let http: HttpTestingController;

  const personalOrganization: OrganizationSummary = {
    id: '01KPERSONAL',
    name: 'Milan workspace',
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
    joined_at: '2026-07-24T09:00:00.000000Z',
    is_active: true,
    created_at: '2026-07-24T09:00:00.000000Z',
    updated_at: '2026-07-24T09:00:00.000000Z',
  };

  const businessOrganization: OrganizationSummary = {
    id: '01KBUSINESS',
    name: 'Procura Global',
    type: 'business',
    role: 'analyst',
    capabilities: ['organization.members.view'],
    joined_at: '2026-07-24T10:00:00.000000Z',
    is_active: false,
    created_at: '2026-07-24T10:00:00.000000Z',
    updated_at: '2026-07-24T10:00:00.000000Z',
  };

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });

    service = TestBed.inject(OrganizationContextService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    http.verify();
  });

  it('hydrates the organization resolved with the authenticated user', () => {
    service.hydrate(personalOrganization);

    expect(service.organizations()).toEqual([personalOrganization]);
    expect(service.activeOrganization()?.id).toBe(personalOrganization.id);
  });

  it('loads every workspace available to the current account', async () => {
    const result = firstValueFrom(service.load());
    const request = http.expectOne('/api/v1/organizations');

    expect(request.request.method).toBe('GET');
    request.flush({ data: [personalOrganization, businessOrganization] });

    expect(await result).toEqual([personalOrganization, businessOrganization]);
    expect(service.state()).toBe('ready');
    expect(service.activeOrganization()?.id).toBe(personalOrganization.id);
  });

  it('activates a workspace and updates the local context atomically', async () => {
    service.hydrate(personalOrganization);

    const result = firstValueFrom(service.activate(businessOrganization.id));
    const request = http.expectOne(
      `/api/v1/organizations/${businessOrganization.id}/activate`,
    );

    expect(request.request.method).toBe('PUT');
    request.flush({
      data: {
        ...businessOrganization,
        is_active: true,
      },
    });

    await result;

    expect(service.activeOrganization()?.id).toBe(businessOrganization.id);
    expect(
      service
        .organizations()
        .find((organization) => organization.id === personalOrganization.id),
    ).toEqual({
        ...personalOrganization,
        is_active: false,
    });
    expect(service.switchingOrganizationId()).toBeNull();
  });

  it('preserves the active workspace when activation is rejected', async () => {
    service.hydrate(personalOrganization);

    const result = firstValueFrom(service.activate('01KUNAVAILABLE')).catch(() => null);
    const request = http.expectOne('/api/v1/organizations/01KUNAVAILABLE/activate');

    request.flush(
      { message: 'Not found.' },
      {
        status: 404,
        statusText: 'Not Found',
      },
    );

    await result;

    expect(service.activeOrganization()?.id).toBe(personalOrganization.id);
    expect(service.error()).toBe('That workspace is no longer available to your account.');
    expect(service.switchingOrganizationId()).toBeNull();
  });
});
