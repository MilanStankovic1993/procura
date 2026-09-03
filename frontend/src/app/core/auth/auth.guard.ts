import { inject } from '@angular/core';
import { CanMatchFn, Router } from '@angular/router';
import { map } from 'rxjs';

import { AuthService } from './auth.service';

export const authGuard: CanMatchFn = (_route, segments) => {
  const auth = inject(AuthService);
  const router = inject(Router);

  return auth.ensureAuthenticated().pipe(
    map((authenticated) => {
      if (authenticated) {
        return true;
      }

      if (auth.state() === 'organization-unavailable') {
        return router.createUrlTree(['/workspace-unavailable']);
      }

      return router.createUrlTree(['/login'], {
        queryParams: { returnUrl: requestedUrl(router, segments) },
      });
    }),
  );
};

export const verifiedGuard: CanMatchFn = (_route, segments) => {
  const auth = inject(AuthService);
  const router = inject(Router);

  return auth.ensureAuthenticated().pipe(
    map((authenticated) => {
      const returnUrl = requestedUrl(router, segments);

      if (!authenticated) {
        if (auth.state() === 'organization-unavailable') {
          return router.createUrlTree(['/workspace-unavailable']);
        }

        return router.createUrlTree(['/login'], {
          queryParams: { returnUrl },
        });
      }

      if (auth.state() === 'unverified') {
        return router.createUrlTree(['/verify-email'], {
          queryParams: { returnUrl },
        });
      }

      return true;
    }),
  );
};

export const guestGuard: CanMatchFn = () => {
  const auth = inject(AuthService);
  const router = inject(Router);

  return auth.ensureAuthenticated().pipe(
    map((authenticated) => {
      if (!authenticated) {
        return auth.state() === 'organization-unavailable'
          ? router.createUrlTree(['/workspace-unavailable'])
          : true;
      }

      if (auth.state() === 'unverified') {
        return router.createUrlTree(['/verify-email']);
      }

      return router.createUrlTree(['/app']);
    }),
  );
};

function requestedUrl(router: Router, segments: readonly { path: string }[]): string {
  return (
    router.getCurrentNavigation()?.extractedUrl.toString() ??
    `/${segments.map((segment) => segment.path).join('/')}`
  );
}
