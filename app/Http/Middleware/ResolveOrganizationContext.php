<?php

namespace App\Http\Middleware;

use App\Exceptions\Tenancy\ActiveOrganizationUnavailable;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use App\Tenancy\ResolveActiveOrganization;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveOrganizationContext
{
    public function __construct(
        private readonly ResolveActiveOrganization $resolver,
        private readonly OrganizationContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return new JsonResponse(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $this->context->set($this->resolver->resolve($user));
        } catch (ActiveOrganizationUnavailable) {
            return new JsonResponse([
                'message' => 'No organization workspace is available for this account.',
                'code' => 'organization_context_unavailable',
            ], Response::HTTP_CONFLICT);
        }

        return $next($request);
    }
}
