export type OrganizationType = 'personal' | 'business';
export type OrganizationRole = 'owner' | 'administrator' | 'analyst' | 'viewer';
export type OrganizationCapability =
  | 'organization.update'
  | 'organization.members.view'
  | 'organization.members.invite'
  | 'organization.members.manage'
  | 'organization.ownership.transfer'
  | 'organization.audit.view'
  | 'listings.view'
  | 'listings.manage'
  | 'owned-products.view'
  | 'owned-products.manage'
  | 'analyses.view'
  | 'analyses.manage'
  | 'saved-searches.view'
  | 'saved-searches.manage'
  | 'notifications.view';

export interface OrganizationSummary {
  readonly id: string;
  readonly name: string;
  readonly type: OrganizationType;
  readonly role: OrganizationRole;
  readonly capabilities: readonly OrganizationCapability[];
  readonly joined_at: string | null;
  readonly is_active: boolean;
  readonly created_at: string | null;
  readonly updated_at: string | null;
}

export interface OrganizationMember {
  readonly id: string;
  readonly name: string;
  readonly email: string;
  readonly role: OrganizationRole;
  readonly joined_at: string | null;
  readonly is_current_user: boolean;
}

export interface OrganizationInvitation {
  readonly id: string;
  readonly email: string;
  readonly role: OrganizationRole;
  readonly expires_at: string | null;
  readonly created_at: string | null;
  readonly invited_by?: { readonly name: string; readonly email: string } | null;
}

export interface OrganizationAuditEvent {
  readonly id: string;
  readonly event: string;
  readonly actor?: { readonly name: string; readonly email: string } | null;
  readonly subject?: { readonly name: string; readonly email: string } | null;
  readonly metadata: Readonly<Record<string, unknown>> | null;
  readonly created_at: string | null;
}

export interface OrganizationManagement {
  readonly organization: OrganizationSummary;
  readonly capabilities: readonly OrganizationCapability[];
  readonly assignable_roles: readonly OrganizationRole[];
  readonly members: readonly OrganizationMember[];
  readonly pending_invitations: readonly OrganizationInvitation[];
  readonly audit_events: readonly OrganizationAuditEvent[];
}
