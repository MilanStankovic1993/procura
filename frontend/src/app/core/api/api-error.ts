import { HttpErrorResponse } from '@angular/common/http';

interface ValidationErrorBody {
  readonly message?: string;
  readonly errors?: Readonly<Record<string, readonly string[]>>;
}

export function apiErrorMessage(error: unknown, fallback: string): string {
  if (!(error instanceof HttpErrorResponse)) {
    return fallback;
  }

  const body = error.error as ValidationErrorBody | null;
  const validationMessage = body?.errors
    ? Object.values(body.errors).flat().find((message) => message.length > 0)
    : undefined;

  return validationMessage ?? body?.message ?? fallback;
}
