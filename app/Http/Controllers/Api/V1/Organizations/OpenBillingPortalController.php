<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Actions\Billing\OpenBillingPortal;
use App\Http\Controllers\Controller;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class OpenBillingPortalController extends Controller
{
    public function __invoke(
        OrganizationContext $context,
        OpenBillingPortal $portal,
    ): JsonResponse {
        $organization = $context->organization();
        Gate::authorize('manageBilling', $organization);

        return response()->json([
            'data' => [
                'url' => $portal->url(
                    $organization,
                    rtrim((string) config('app.frontend_url'), '/').'/app/subscription',
                ),
            ],
        ]);
    }
}
