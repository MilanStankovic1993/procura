import { OrganizationSummary } from '../organizations/organization.models';
import { SupportedLocale } from '../i18n/i18n.models';

export interface AuthenticatedUser {
  readonly id: number;
  readonly name: string;
  readonly email: string;
  readonly preferred_locale: SupportedLocale;
  readonly email_verified_at: string | null;
  readonly current_organization: OrganizationSummary;
  readonly created_at: string | null;
  readonly updated_at: string | null;
}

export interface LoginCredentials {
  readonly email: string;
  readonly password: string;
  readonly remember: boolean;
}

export interface RegistrationData {
  readonly name: string;
  readonly email: string;
  readonly password: string;
  readonly password_confirmation: string;
  readonly preferred_locale: SupportedLocale;
}

export interface PreferredLocaleResponse {
  readonly preferred_locale: SupportedLocale;
}

export interface PasswordResetData {
  readonly token: string;
  readonly email: string;
  readonly password: string;
  readonly password_confirmation: string;
}

export interface MessageResponse {
  readonly message: string;
}

export interface PasswordConfirmationStatus {
  readonly confirmed: boolean;
}
