import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { firstValueFrom } from 'rxjs';

import { OrganizationManagementService } from './organization-management.service';

describe('OrganizationManagementService', () => {
  let service: OrganizationManagementService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    service = TestBed.inject(OrganizationManagementService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('loads management data only from the active organization endpoint', async () => {
    const result = firstValueFrom(service.get());
    const request = http.expectOne('/api/v1/organization/management');

    expect(request.request.method).toBe('GET');
    request.flush({
      data: {
        organization: {
          id: '01KORG',
          name: 'Procura Global',
          type: 'business',
          role: 'owner',
          capabilities: ['organization.members.manage'],
          joined_at: null,
          is_active: true,
          created_at: null,
          updated_at: null,
        },
        capabilities: ['organization.members.manage'],
        assignable_roles: ['administrator', 'analyst', 'viewer'],
        members: [],
        pending_invitations: [],
        audit_events: [],
      },
    });

    expect((await result).organization.id).toBe('01KORG');
  });

  it('uses tenant-scoped member routes without accepting an organization id', async () => {
    const result = firstValueFrom(service.updateMemberRole('01KMEMBER', 'viewer'));
    const request = http.expectOne('/api/v1/organization/members/01KMEMBER');

    expect(request.request.method).toBe('PATCH');
    expect(request.request.body).toEqual({ role: 'viewer' });
    request.flush({
      data: {
        id: '01KMEMBER',
        name: 'Member',
        email: 'member@example.com',
        role: 'viewer',
        joined_at: null,
        is_current_user: false,
      },
    });

    expect((await result).role).toBe('viewer');
  });
});
