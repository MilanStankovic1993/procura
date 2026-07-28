import { HttpErrorResponse } from '@angular/common/http';

interface ValidationErrorBody {
  readonly message?: string;
  readonly errors?: Record<string, string[]>;
}

export interface AuthFormError {
  readonly message: string;
  readonly fields: Record<string, string[]>;
}

interface AuthErrorTranslator {
  translate(
    key: 'authError.validation' | 'authError.sessionExpired' | 'authError.tooManyAttempts',
  ): string;
}

export function authFormError(
  error: unknown,
  fallback: string,
  i18n: AuthErrorTranslator,
): AuthFormError {
  if (error instanceof HttpErrorResponse) {
    const body = error.error as ValidationErrorBody | null;

    if (error.status === 422) {
      return {
        message: body?.message ?? i18n.translate('authError.validation'),
        fields: body?.errors ?? {},
      };
    }

    if (error.status === 419) {
      return {
        message: i18n.translate('authError.sessionExpired'),
        fields: {},
      };
    }

    if (error.status === 429) {
      return {
        message: i18n.translate('authError.tooManyAttempts'),
        fields: {},
      };
    }
  }

  return { message: fallback, fields: {} };
}
