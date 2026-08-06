<?php

namespace App\Http\Middleware;

use App\Enums\Api\ApiErrorCode;
use App\Models\User;
use App\Operations\Capacity\AnalysisPipelineWorkloadConfiguration;
use App\Operations\Capacity\AnalysisPipelineWorkloadPermitStore;
use App\Support\Localization\ApiErrorLocalizer;
use App\Support\Localization\RequestLocaleResolver;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class RequireAnalysisPipelineWorkloadPermit
{
    public function __construct(
        private AnalysisPipelineWorkloadConfiguration $configuration,
        private AnalysisPipelineWorkloadPermitStore $permits,
        private ApiErrorLocalizer $localizer,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $header = $this->configuration->header();
        } catch (Throwable) {
            return $this->reject($request);
        }

        if (! $request->headers->has($header)) {
            return $next($request);
        }

        $actor = $request->user();
        $token = $request->header($header);

        if (
            app()->environment('production')
            || ! app()->environment('staging')
            || ! $this->configuration->enabled()
            || ! $actor instanceof User
            || ! is_string($token)
            || ! $this->permits->consume($actor, $token)
        ) {
            return $this->reject($request);
        }

        return $next($request);
    }

    private function reject(Request $request): JsonResponse
    {
        $code = ApiErrorCode::AnalysisWorkloadPermitRejected;
        $response = new JsonResponse([
            'message' => $this->localizer->message($code, $request),
            'code' => $code->value,
        ], Response::HTTP_FORBIDDEN);
        $locale = $request->attributes->get(
            RequestLocaleResolver::REQUEST_ATTRIBUTE,
        );

        if (is_string($locale)) {
            $response->headers->set('Content-Language', $locale);
        }

        return $response;
    }
}
