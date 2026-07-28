<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Billing\BillingConfiguration;
use App\Http\Controllers\Controller;
use App\Models\SubscriptionUsage;
use App\Subscriptions\SubscriptionEntitlements;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationSubscriptionController extends Controller
{
    public function __invoke(
        Request $request,
        OrganizationContext $context,
        SubscriptionEntitlements $entitlements,
        BillingConfiguration $billing,
    ): JsonResponse {
        $organization = $context->organization();
        $summary = $entitlements->summary($organization);
        $assignment = $organization->planAssignment()->first();
        $providerSubscription = $organization->subscription('default');
        $canManageBilling = $request->user()->can('manageBilling', $organization);
        $usage = SubscriptionUsage::query()
            ->where('organization_id', $organization->getKey())
            ->where('period_start', $summary['period_start']->toDateString())
            ->pluck('used', 'feature_code');

        return response()->json([
            'data' => [
                'plan' => [
                    'code' => $summary['plan']->code->value,
                    'version' => $summary['plan']->version,
                    'name' => $summary['plan']->name,
                    'description' => $summary['plan']->description,
                ],
                'period' => [
                    'starts_at' => $summary['period_start']->toDateString(),
                    'ends_at' => $summary['period_end']->toDateString(),
                ],
                'features' => $summary['features']->map(fn ($feature) => [
                    'code' => $feature->feature_code->value,
                    'enabled' => $feature->is_enabled,
                    'limit' => $feature->limit,
                    'used' => $feature->feature_code->isMetered()
                        ? (int) ($usage[$feature->feature_code->value] ?? 0)
                        : null,
                    'metered' => $feature->feature_code->isMetered(),
                ])->values(),
                'billing' => [
                    'provider' => $billing->provider(),
                    'configured' => $billing->providerReady(),
                    'checkout_enabled' => $billing->checkoutEnabled(),
                    'can_manage' => $canManageBilling,
                    'has_customer' => $organization->hasStripeId(),
                    'portal_available' => $canManageBilling
                        && $billing->providerReady()
                        && $organization->hasStripeId(),
                    'assignment_source' => $assignment?->source ?? 'default',
                    'subscription_status' => $providerSubscription?->stripe_status,
                    'trial_ends_at' => $providerSubscription?->trial_ends_at?->toIso8601String(),
                    'ends_at' => $providerSubscription?->ends_at?->toIso8601String(),
                    'offers' => $billing->publicOffers(),
                ],
            ],
        ]);
    }
}
