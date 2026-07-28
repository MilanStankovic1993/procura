import { HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';

import { I18nService } from './i18n.service';

export const localeInterceptor: HttpInterceptorFn = (request, next) => {
  const locale = inject(I18nService).locale();

  return next(
    request.clone({
      setHeaders: {
        'Accept-Language': locale,
      },
    }),
  );
};
