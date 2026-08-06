<?php

use App\Exceptions\BillingException;
use App\Exceptions\BuyerDecisionConflictException;
use App\Exceptions\MarketplaceImportConflictException;
use App\Exceptions\OutcomeTrackingConflictException;
use App\Exceptions\PrivacyRequestConflictException;
use App\Exceptions\SalePortfolioConflictException;
use App\Http\Middleware\RequireAnalysisPipelineWorkloadPermit;
use App\Http\Middleware\RequireStripeWebhookConfiguration;
use App\Http\Middleware\ResolveOrganizationContext;
use App\Http\Middleware\SetAuthenticatedUserLocale;
use App\Support\Localization\ApiErrorLocalizer;
use App\Support\Localization\RequestLocaleResolver;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustHosts();
        $middleware->statefulApi();
        $middleware->api(append: [
            SetAuthenticatedUserLocale::class,
        ]);
        $middleware->appendToPriorityList(
            Authenticate::class,
            SetAuthenticatedUserLocale::class,
        );
        $middleware->alias([
            'analysis-workload-permit' => RequireAnalysisPipelineWorkloadPermit::class,
            'organization.context' => ResolveOrganizationContext::class,
            'stripe-webhook-configured' => RequireStripeWebhookConfiguration::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->respond(
            static function (
                Response $response,
                Throwable $exception,
                Request $request,
            ): Response {
                $locale = $request->attributes->get(
                    RequestLocaleResolver::REQUEST_ATTRIBUTE,
                );

                if (
                    is_string($locale)
                    && $request->is('api/*')
                ) {
                    $response->headers->set(
                        'Content-Language',
                        $locale,
                    );
                }

                return $response;
            },
        );
        $exceptions->render(
            static function (
                PrivacyRequestConflictException $exception,
                Request $request,
            ): JsonResponse {
                $message = app(ApiErrorLocalizer::class)->message(
                    $exception->errorCode,
                    $request,
                );

                return response()->json([
                    'message' => $message,
                    'code' => $exception->errorCode->value,
                    'errors' => [
                        $exception->field => [$message],
                    ],
                ], 409);
            },
        );
        $exceptions->render(
            static function (
                MarketplaceImportConflictException $exception,
                Request $request,
            ): JsonResponse {
                $message = app(ApiErrorLocalizer::class)->message(
                    $exception->errorCode,
                    $request,
                );

                return response()->json([
                    'message' => $message,
                    'code' => $exception->errorCode->value,
                ], 409);
            },
        );
        $exceptions->render(
            static function (
                BillingException $exception,
                Request $request,
            ): JsonResponse {
                $message = app(ApiErrorLocalizer::class)->message(
                    $exception->errorCode,
                    $request,
                );

                return response()->json([
                    'message' => $message,
                    'code' => $exception->errorCode->value,
                ], $exception->status);
            },
        );
        $exceptions->render(
            static function (
                BuyerDecisionConflictException $exception,
                Request $request,
            ): JsonResponse {
                $message = app(ApiErrorLocalizer::class)->message(
                    $exception->errorCode,
                    $request,
                );

                return response()->json([
                    'message' => $message,
                    'code' => $exception->errorCode->value,
                    'errors' => [
                        $exception->field => [$message],
                    ],
                ], 409);
            },
        );
        $exceptions->render(
            static function (
                SalePortfolioConflictException $exception,
                Request $request,
            ): JsonResponse {
                $message = app(ApiErrorLocalizer::class)->message(
                    $exception->errorCode,
                    $request,
                );

                return response()->json([
                    'message' => $message,
                    'code' => $exception->errorCode->value,
                    'errors' => [
                        $exception->field => [$message],
                    ],
                ], 409);
            },
        );
        $exceptions->render(
            static function (
                OutcomeTrackingConflictException $exception,
                Request $request,
            ): JsonResponse {
                $message = app(ApiErrorLocalizer::class)->message(
                    $exception->errorCode,
                    $request,
                );

                return response()->json([
                    'message' => $message,
                    'code' => $exception->errorCode->value,
                    'errors' => [
                        $exception->field => [$message],
                    ],
                ], 409);
            },
        );
    })->create();
