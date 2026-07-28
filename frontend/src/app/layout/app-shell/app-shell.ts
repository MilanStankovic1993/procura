import { Component, inject, OnInit, signal } from '@angular/core';
import { Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { finalize } from 'rxjs';

import { AuthService } from '../../core/auth/auth.service';
import { I18nService } from '../../core/i18n/i18n.service';
import { TranslationKey } from '../../core/i18n/locales/en';
import { TranslatePipe } from '../../core/i18n/translate.pipe';
import { OrganizationContextService } from '../../core/organizations/organization-context.service';
import { LanguageSwitcherComponent } from '../../shared/language-switcher/language-switcher.component';

@Component({
  selector: 'app-shell',
  imports: [
    LanguageSwitcherComponent,
    RouterLink,
    RouterLinkActive,
    RouterOutlet,
    TranslatePipe,
  ],
  templateUrl: './app-shell.html',
  styleUrl: './app-shell.scss',
})
export class AppShell implements OnInit {
  private readonly auth = inject(AuthService);
  private readonly i18n = inject(I18nService);
  private readonly organizationContext = inject(OrganizationContextService);
  private readonly router = inject(Router);

  protected readonly user = this.auth.user;
  protected readonly organizations = this.organizationContext.organizations;
  protected readonly activeOrganization = this.organizationContext.activeOrganization;
  protected readonly organizationState = this.organizationContext.state;
  protected readonly organizationError = this.organizationContext.error;
  protected readonly switchingOrganizationId =
    this.organizationContext.switchingOrganizationId;
  protected readonly isSigningOut = signal(false);

  ngOnInit(): void {
    this.organizationContext.hydrate(this.user()?.current_organization ?? null);
    this.loadOrganizations();
  }

  protected selectOrganization(event: Event): void {
    const select = event.target as HTMLSelectElement;
    const organizationId = select.value;

    if (organizationId === '') {
      return;
    }

    this.organizationContext.activate(organizationId).subscribe({
      next: (organization) => this.auth.setCurrentOrganization(organization),
      error: () => {
        select.value = this.activeOrganization()?.id ?? '';
      },
    });
  }

  protected retryOrganizations(): void {
    this.loadOrganizations();
  }

  protected signOut(): void {
    this.isSigningOut.set(true);
    this.auth
      .logout()
      .pipe(finalize(() => this.isSigningOut.set(false)))
      .subscribe({
        next: () => {
          this.organizationContext.reset();
          void this.router.navigateByUrl('/login');
        },
        error: () => {
          this.organizationContext.reset();
          void this.router.navigateByUrl('/login');
        },
      });
  }

  protected organizationRole(role: string): string {
    return this.i18n.translate(`organization.role.${role}` as TranslationKey);
  }

  protected organizationType(type: string | undefined): string {
    return this.i18n.translate(
      `organization.type.${type ?? 'personal'}` as TranslationKey,
    );
  }

  private loadOrganizations(): void {
    this.organizationContext.load().subscribe({
      error: () => undefined,
    });
  }
}
