import { HttpErrorResponse } from '@angular/common/http';
import { Component, computed, inject, OnInit, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { finalize } from 'rxjs';

import { AuthService } from '../../core/auth/auth.service';
import { I18nService } from '../../core/i18n/i18n.service';
import { TranslationKey } from '../../core/i18n/locales/en';
import { TranslatePipe } from '../../core/i18n/translate.pipe';
import { OrganizationContextService } from '../../core/organizations/organization-context.service';
import { OrganizationManagementService } from '../../core/organizations/organization-management.service';
import {
  OrganizationCapability,
  OrganizationManagement,
  OrganizationRole,
} from '../../core/organizations/organization.models';

@Component({
  selector: 'app-organization-management-page',
  imports: [ReactiveFormsModule, TranslatePipe],
  templateUrl: './organization-management.page.html',
  styleUrl: './organization-management.page.scss',
})
export class OrganizationManagementPage implements OnInit {
  private readonly auth = inject(AuthService);
  private readonly context = inject(OrganizationContextService);
  private readonly managementApi = inject(OrganizationManagementService);
  private readonly formBuilder = inject(FormBuilder);
  private readonly i18n = inject(I18nService);

  protected readonly activeOrganization = this.context.activeOrganization;
  protected readonly management = signal<OrganizationManagement | null>(null);
  protected readonly loading = signal(false);
  protected readonly submitting = signal<string | null>(null);
  protected readonly error = signal<string | null>(null);
  protected readonly success = signal<string | null>(null);
  protected readonly isBusiness = computed(
    () => this.activeOrganization()?.type === 'business',
  );

  protected readonly createForm = this.formBuilder.nonNullable.group({
    name: ['', [Validators.required, Validators.maxLength(160)]],
  });
  protected readonly renameForm = this.formBuilder.nonNullable.group({
    name: ['', [Validators.required, Validators.maxLength(160)]],
  });
  protected readonly inviteForm = this.formBuilder.nonNullable.group({
    email: ['', [Validators.required, Validators.email]],
    role: ['analyst' as OrganizationRole, [Validators.required]],
  });

  ngOnInit(): void {
    if (this.isBusiness()) {
      this.load();
    }
  }

  protected can(capability: OrganizationCapability): boolean {
    return this.management()?.capabilities.includes(capability) ?? false;
  }

  protected createOrganization(): void {
    if (this.createForm.invalid) {
      this.createForm.markAllAsTouched();
      return;
    }

    this.run('create', () =>
      this.context.create(this.createForm.getRawValue().name).subscribe({
        next: (organization) => {
          this.auth.setCurrentOrganization(organization);
          this.createForm.reset();
          this.success.set(this.i18n.translate('organizationManagement.created'));
          this.load();
        },
        error: (error: unknown) => this.handleError(error),
      }),
    );
  }

  protected rename(): void {
    if (this.renameForm.invalid) {
      this.renameForm.markAllAsTouched();
      return;
    }

    this.run('rename', () =>
      this.managementApi.rename(this.renameForm.getRawValue().name).subscribe({
        next: (organization) => {
          this.context.updateActive(organization);
          this.auth.setCurrentOrganization(organization);
          this.management.update((current) =>
            current === null ? null : { ...current, organization },
          );
          this.success.set(this.i18n.translate('organizationManagement.renamed'));
          this.submitting.set(null);
        },
        error: (error: unknown) => this.handleError(error),
      }),
    );
  }

  protected invite(): void {
    if (this.inviteForm.invalid) {
      this.inviteForm.markAllAsTouched();
      return;
    }

    const value = this.inviteForm.getRawValue();
    this.run('invite', () =>
      this.managementApi.invite(value.email, value.role).subscribe({
        next: (invitation) => {
          this.management.update((current) =>
            current === null
              ? null
              : {
                  ...current,
                  pending_invitations: [invitation, ...current.pending_invitations],
                },
          );
          this.inviteForm.reset({ email: '', role: 'analyst' });
          this.success.set(this.i18n.translate('organizationManagement.invited'));
          this.submitting.set(null);
        },
        error: (error: unknown) => this.handleError(error),
      }),
    );
  }

  protected changeRole(memberId: string, event: Event): void {
    const role = (event.target as HTMLSelectElement).value as OrganizationRole;
    this.run(memberId, () =>
      this.managementApi.updateMemberRole(memberId, role).subscribe({
        next: (updated) => {
          this.management.update((current) =>
            current === null
              ? null
              : {
                  ...current,
                  members: current.members.map((member) =>
                    member.id === updated.id ? updated : member,
                  ),
                },
          );
          this.success.set(
            this.i18n.translate('organizationManagement.roleUpdated'),
          );
          this.submitting.set(null);
        },
        error: (error: unknown) => {
          this.handleError(error);
          this.load();
        },
      }),
    );
  }

  protected remove(memberId: string, name: string): void {
    if (
      !confirm(
        this.i18n.translate('organizationManagement.confirmRemove', { name }),
      )
    ) {
      return;
    }

    this.run(memberId, () =>
      this.managementApi.removeMember(memberId).subscribe({
        next: () => {
          this.management.update((current) =>
            current === null
              ? null
              : { ...current, members: current.members.filter((member) => member.id !== memberId) },
          );
          this.success.set(this.i18n.translate('organizationManagement.removed'));
          this.submitting.set(null);
        },
        error: (error: unknown) => this.handleError(error),
      }),
    );
  }

  protected transfer(memberId: string, name: string): void {
    if (
      !confirm(
        this.i18n.translate('organizationManagement.confirmTransfer', { name }),
      )
    ) {
      return;
    }

    this.run(memberId, () =>
      this.managementApi.transferOwnership(memberId).subscribe({
        next: () => {
          this.success.set(
            this.i18n.translate('organizationManagement.transferred'),
          );
          this.submitting.set(null);
          this.load();
          this.context.load().subscribe({ error: () => undefined });
        },
        error: (error: unknown) => this.handleError(error),
      }),
    );
  }

  protected revoke(invitationId: string): void {
    this.run(invitationId, () =>
      this.managementApi.revokeInvitation(invitationId).subscribe({
        next: () => {
          this.management.update((current) =>
            current === null
              ? null
              : {
                  ...current,
                  pending_invitations: current.pending_invitations.filter(
                    (invitation) => invitation.id !== invitationId,
                  ),
                },
          );
          this.success.set(this.i18n.translate('organizationManagement.revoked'));
          this.submitting.set(null);
        },
        error: (error: unknown) => this.handleError(error),
      }),
    );
  }

  protected load(): void {
    this.loading.set(true);
    this.error.set(null);
    this.managementApi
      .get()
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: (management) => {
          this.management.set(management);
          this.renameForm.setValue({ name: management.organization.name });
        },
        error: (error: unknown) => this.handleError(error),
      });
  }

  protected roleLabel(role: OrganizationRole): string {
    const keys: Readonly<Record<OrganizationRole, TranslationKey>> = {
      owner: 'organization.role.owner',
      administrator: 'organization.role.administrator',
      analyst: 'organization.role.analyst',
      viewer: 'organization.role.viewer',
    };

    return this.i18n.translate(keys[role]);
  }

  protected eventLabel(event: string): string {
    const keys: Readonly<Record<string, TranslationKey>> = {
      'organization.created': 'organizationManagement.event.organizationCreated',
      'organization.renamed': 'organizationManagement.event.organizationRenamed',
      'organization.invitation.created':
        'organizationManagement.event.invitationCreated',
      'organization.invitation.revoked':
        'organizationManagement.event.invitationRevoked',
      'organization.invitation.accepted':
        'organizationManagement.event.invitationAccepted',
      'organization.member.role_changed':
        'organizationManagement.event.memberRoleChanged',
      'organization.member.removed':
        'organizationManagement.event.memberRemoved',
      'organization.ownership.transferred':
        'organizationManagement.event.ownershipTransferred',
    };
    const key = keys[event];

    return key === undefined ? event : this.i18n.translate(key);
  }

  protected date(
    value: string | null,
    options: Intl.DateTimeFormatOptions = { dateStyle: 'medium' },
  ): string {
    return value === null
      ? this.i18n.translate('common.notSet')
      : this.i18n.formatDate(value, options);
  }

  private run(key: string, operation: () => void): void {
    this.error.set(null);
    this.success.set(null);
    this.submitting.set(key);
    operation();
  }

  private handleError(error: unknown): void {
    this.submitting.set(null);
    if (error instanceof HttpErrorResponse && error.status === 422) {
      this.error.set(this.i18n.translate('organizationManagement.formError'));
      return;
    }
    this.error.set(this.i18n.translate('organizationManagement.operationError'));
  }
}
