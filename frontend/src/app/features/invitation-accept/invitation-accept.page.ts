import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Component, inject, OnInit, signal } from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { finalize, map } from 'rxjs';

import { ApiEnvelope } from '../../core/api/api.models';
import { AuthService } from '../../core/auth/auth.service';
import { I18nService } from '../../core/i18n/i18n.service';
import { TranslatePipe } from '../../core/i18n/translate.pipe';
import { OrganizationContextService } from '../../core/organizations/organization-context.service';
import { OrganizationSummary } from '../../core/organizations/organization.models';
import { LanguageSwitcherComponent } from '../../shared/language-switcher/language-switcher.component';

@Component({
  selector: 'app-invitation-accept-page',
  imports: [LanguageSwitcherComponent, RouterLink, TranslatePipe],
  templateUrl: './invitation-accept.page.html',
  styleUrl: './invitation-accept.page.scss',
})
export class InvitationAcceptPage implements OnInit {
  private readonly http = inject(HttpClient);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly auth = inject(AuthService);
  private readonly i18n = inject(I18nService);
  private readonly context = inject(OrganizationContextService);

  protected readonly token = signal<string | null>(null);
  protected readonly submitting = signal(false);
  protected readonly error = signal<string | null>(null);

  ngOnInit(): void {
    this.token.set(this.route.snapshot.queryParamMap.get('token'));
  }

  protected accept(): void {
    const token = this.token();
    if (!token) {
      this.error.set(this.i18n.translate('invitation.incomplete'));
      return;
    }

    this.submitting.set(true);
    this.error.set(null);
    this.http
      .post<ApiEnvelope<OrganizationSummary>>('/api/v1/organization-invitations/accept', {
        token,
      })
      .pipe(
        map((response) => response.data),
        finalize(() => this.submitting.set(false)),
      )
      .subscribe({
        next: (organization) => {
          this.auth.setCurrentOrganization(organization);
          this.context.reset();
          this.context.hydrate(organization);
          void this.router.navigateByUrl('/app/organization');
        },
        error: (error: unknown) => {
          if (error instanceof HttpErrorResponse && error.status === 422) {
            this.error.set(
              error.error?.errors?.token?.[0] ??
                this.i18n.translate('invitation.unavailable'),
            );
            return;
          }
          this.error.set(this.i18n.translate('invitation.error'));
        },
      });
  }
}
