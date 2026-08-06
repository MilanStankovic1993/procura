<?php

namespace App\Http\Middleware;

use App\Enums\Api\ApiErrorCode;
use App\Models\User;
use App\Operations\Capacity\BrowserWorkloadConfiguration;
use App\Operations\Capacity\BrowserWorkloadPermitStore;
use App\Support\Localization\ApiErrorLocalizer;
use App\Support\Localization\RequestLocaleResolver;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class RequireBrowserWorkloadPermit
{
    public function __construct(
        private BrowserWorkloadConfiguration $configuration,
        private BrowserWorkloadPermitStore $permits,
        private ApiErrorLocalizer $localizer,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $header = $this->configuration->header();
            $scenarios = $this->configuration->scenarios();
        } catch (Throwable) {
            return $this->reject($request);
        }

        $actor = $request->user();
        $token = $request->header($header);
        $scenario = $request->input('scenario');
        $contractHash = $request->input('contract_hash');

        if (
            app()->environment('production')
            || ! app()->environment('staging')
            || ! $this->configuration->enabled()
            || ! $actor instanceof User
            || ! is_string($token)
            || ! is_string($scenario)
            || ! in_array($scenario, $scenarios, true)
            || ! is_string($contractHash)
            || ! $this->permits->consume($actor, $token, $scenario, $contractHash)
        ) {
            return $this->reject($request);
        }

        return $next($request);
    }

    private function reject(Request $request): JsonResponse
    {
        $code = ApiErrorCode::BrowserWorkloadPermitRejected;
        $response = new JsonResponse([
            'message' => $this->localizer->message($code, $request),
            'code' => $code->value,
        ], Response::HTTP_FORBIDDEN);
        $locale = $request->attributes->get(RequestLocaleResolver::REQUEST_ATTRIBUTE);

        if (is_string($locale)) {
            $response->headers->set('Content-Language', $locale);
        }

        return $response;
    }
}
