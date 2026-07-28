export interface SubscriptionPlan {
  readonly code: 'free' | 'starter' | 'pro' | 'business';
  readonly version: number;
  readonly name: string;
  readonly description: string;
}

export interface SubscriptionFeature {
  readonly code: string;
  readonly enabled: boolean;
  readonly limit: number | null;
  readonly used: number | null;
  readonly metered: boolean;
}

export type BillingInterval = 'monthly' | 'yearly';
export type CheckoutPlanCode = 'starter' | 'pro';

export interface BillingOffer {
  readonly plan: CheckoutPlanCode;
  readonly interval: BillingInterval;
  readonly amount_minor: number;
  readonly currency: string;
  readonly currency_minor_unit: number;
  readonly available: boolean;
}

export interface OrganizationBilling {
  readonly provider: string;
  readonly configured: boolean;
  readonly checkout_enabled: boolean;
  readonly can_manage: boolean;
  readonly has_customer: boolean;
  readonly portal_available: boolean;
  readonly assignment_source: 'default' | 'manual' | 'stripe';
  readonly subscription_status: string | null;
  readonly trial_ends_at: string | null;
  readonly ends_at: string | null;
  readonly offers: readonly BillingOffer[];
}

export interface OrganizationSubscription {
  readonly plan: SubscriptionPlan;
  readonly period: {
    readonly starts_at: string;
    readonly ends_at: string;
  };
  readonly features: readonly SubscriptionFeature[];
  readonly billing: OrganizationBilling;
}

export interface BillingDestination {
  readonly url: string;
  readonly expires_at?: string;
}
