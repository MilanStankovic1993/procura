<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Actions\Billing\StartBillingCheckout;
use App\Enums\Subscriptions\BillingInterval;
use App\Enums\Subscriptions\PlanCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Billing\StartBillingCheckoutRequest;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class StartBillingCheckoutController extends Controller
{
    public function __invoke(
        StartBillingCheckoutRequest $request,
        OrganizationContext $context,
        StartBillingCheckout $checkout,
    ): JsonResponse {
        $organization = $context->organization();
        Gate::authorize('manageBilling', $organization);

        $frontendUrl = rtrim((string) config('app.frontend_url'), '/');
        $session = $checkout->start(
            organization: $organization,
            actor: $request->user(),
            plan: PlanCode::from($request->string('plan')->toString()),
            interval: BillingInterval::from($request->string('interval')->toString()),
            idempotencyKey: $request->string('idempotency_key')->toString(),
            successUrl: $frontendUrl.'/app/subscription?checkout=success',
            cancelUrl: $frontendUrl.'/app/subscription?checkout=cancelled',
        );

        return response()->json([
            'data' => [
                'url' => $session->checkout_url,
                'expires_at' => $session->expires_at->toIso8601String(),
            ],
        ], $session->wasRecentlyCreated ? 201 : 200);
    }
}
