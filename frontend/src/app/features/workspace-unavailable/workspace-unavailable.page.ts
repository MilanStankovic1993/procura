import { Component, inject, signal } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { finalize } from 'rxjs';

import { AuthService } from '../../core/auth/auth.service';
import { TranslatePipe } from '../../core/i18n/translate.pipe';
import { LanguageSwitcherComponent } from '../../shared/language-switcher/language-switcher.component';

@Component({
  selector: 'app-workspace-unavailable-page',
  imports: [LanguageSwitcherComponent, RouterLink, TranslatePipe],
  templateUrl: './workspace-unavailable.page.html',
  styleUrl: './workspace-unavailable.page.scss',
})
export class WorkspaceUnavailablePage {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);

  protected readonly message = this.auth.contextError;
  protected readonly isRetrying = signal(false);
  protected readonly isSigningOut = signal(false);

  protected retry(): void {
    this.isRetrying.set(true);
    this.auth
      .loadCurrentUser()
      .pipe(finalize(() => this.isRetrying.set(false)))
      .subscribe({
        next: (user) => {
          if (user !== null) {
            void this.router.navigateByUrl('/app');
          }
        },
        error: () => undefined,
      });
  }

  protected signOut(): void {
    this.isSigningOut.set(true);
    this.auth
      .logout()
      .pipe(finalize(() => this.isSigningOut.set(false)))
      .subscribe({
        next: () => void this.router.navigateByUrl('/login'),
        error: () => void this.router.navigateByUrl('/login'),
      });
  }
}
