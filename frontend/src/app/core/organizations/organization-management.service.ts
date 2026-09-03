import { HttpClient } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { map, Observable } from 'rxjs';

import { ApiEnvelope } from '../api/api.models';
import {
  OrganizationInvitation,
  OrganizationManagement,
  OrganizationMember,
  OrganizationRole,
  OrganizationSummary,
} from './organization.models';

@Injectable({ providedIn: 'root' })
export class OrganizationManagementService {
  private readonly http = inject(HttpClient);

  get(): Observable<OrganizationManagement> {
    return this.http
      .get<ApiEnvelope<OrganizationManagement>>('/api/v1/organization/management')
      .pipe(map((response) => response.data));
  }

  rename(name: string): Observable<OrganizationSummary> {
    return this.http
      .patch<ApiEnvelope<OrganizationSummary>>('/api/v1/organization', { name })
      .pipe(map((response) => response.data));
  }

  invite(email: string, role: OrganizationRole): Observable<OrganizationInvitation> {
    return this.http
      .post<ApiEnvelope<OrganizationInvitation>>('/api/v1/organization/invitations', {
        email,
        role,
      })
      .pipe(map((response) => response.data));
  }

  revokeInvitation(invitationId: string): Observable<void> {
    return this.http.delete<void>(
      `/api/v1/organization/invitations/${encodeURIComponent(invitationId)}`,
    );
  }

  updateMemberRole(
    membershipId: string,
    role: OrganizationRole,
  ): Observable<OrganizationMember> {
    return this.http
      .patch<ApiEnvelope<OrganizationMember>>(
        `/api/v1/organization/members/${encodeURIComponent(membershipId)}`,
        { role },
      )
      .pipe(map((response) => response.data));
  }

  removeMember(membershipId: string): Observable<void> {
    return this.http.delete<void>(
      `/api/v1/organization/members/${encodeURIComponent(membershipId)}`,
    );
  }

  transferOwnership(membershipId: string): Observable<OrganizationMember> {
    return this.http
      .post<ApiEnvelope<OrganizationMember>>(
        `/api/v1/organization/members/${encodeURIComponent(membershipId)}/transfer-ownership`,
        {},
      )
      .pipe(map((response) => response.data));
  }
}
