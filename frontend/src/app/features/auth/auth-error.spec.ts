import { HttpErrorResponse } from '@angular/common/http';
import { describe, expect, it } from 'vitest';

import { authFormError } from './auth-error';

const messages = {
  'authError.validation': 'localized validation',
  'authError.sessionExpired': 'localized session expiry',
  'authError.tooManyAttempts': 'localized rate limit',
} as const;

const translator: Parameters<typeof authFormError>[2] = {
  translate: (key) => messages[key],
};

describe('authFormError', () => {
  it('uses localized validation fallback while preserving server field errors', () => {
    const result = authFormError(
      new HttpErrorResponse({
        status: 422,
        error: {
          errors: {
            email: ['localized field error'],
          },
        },
      }),
      'generic fallback',
      translator,
    );

    expect(result).toEqual({
      message: 'localized validation',
      fields: {
        email: ['localized field error'],
      },
    });
  });

  it('localizes session-expiry and rate-limit fallbacks', () => {
    expect(
      authFormError(
        new HttpErrorResponse({ status: 419 }),
        'generic fallback',
        translator,
      ).message,
    ).toBe('localized session expiry');

    expect(
      authFormError(
        new HttpErrorResponse({ status: 429 }),
        'generic fallback',
        translator,
      ).message,
    ).toBe('localized rate limit');
  });

  it('retains the caller fallback for non-HTTP failures', () => {
    expect(
      authFormError(
        new Error('network failure'),
        'generic fallback',
        translator,
      ),
    ).toEqual({
      message: 'generic fallback',
      fields: {},
    });
  });
});
