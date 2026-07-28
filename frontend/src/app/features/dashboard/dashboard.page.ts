import { Component, inject, OnInit, signal } from '@angular/core';

import { ApiHealth } from '../../core/api/api.models';
import { ApiStatusService } from '../../core/api/api-status.service';
import { AuthService } from '../../core/auth/auth.service';
import { TranslatePipe } from '../../core/i18n/translate.pipe';
import { OrganizationContextService } from '../../core/organizations/organization-context.service';

@Component({
  selector: 'app-dashboard-page',
  imports: [TranslatePipe],
  templateUrl: './dashboard.page.html',
  styleUrl: './dashboard.page.scss',
})
export class DashboardPage implements OnInit {
  private readonly apiStatus = inject(ApiStatusService);
  private readonly auth = inject(AuthService);
  private readonly organizationContext = inject(OrganizationContextService);

  protected readonly user = this.auth.user;
  protected readonly activeOrganization = this.organizationContext.activeOrganization;
  protected readonly health = signal<ApiHealth | null>(null);
  protected readonly healthError = signal(false);

  ngOnInit(): void {
    this.apiStatus.getHealth().subscribe({
      next: (response) => this.health.set(response.data),
      error: () => this.healthError.set(true),
    });
  }
}
