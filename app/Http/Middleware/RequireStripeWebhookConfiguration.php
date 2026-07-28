<?php

namespace App\Http\Middleware;

use App\Billing\BillingConfiguration;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Stripe\Exception\SignatureVerificationException;
use Stripe\WebhookSignature;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class RequireStripeWebhookConfiguration
{
    public function __construct(
        private readonly BillingConfiguration $configuration,
    ) {}

    public function handle(
        Request $request,
        Closure $next,
    ): Response {
        if (! $this->configuration->webhookReady()) {
            return new JsonResponse([
                'message' => 'Stripe webhook processing is not configured.',
                'code' => 'stripe_webhook_not_configured',
            ], 503);
        }

        try {
            WebhookSignature::verifyHeader(
                $request->getContent(),
                $request->header('Stripe-Signature'),
                config('cashier.webhook.secret'),
                config('cashier.webhook.tolerance'),
            );
        } catch (SignatureVerificationException $exception) {
            throw new AccessDeniedHttpException($exception->getMessage(), $exception);
        }

        return $next($request);
    }
}
