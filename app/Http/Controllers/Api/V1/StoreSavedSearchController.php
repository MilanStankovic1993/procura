<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Monitoring\CreateSavedSearch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Monitoring\StoreSavedSearchRequest;
use App\Http\Resources\V1\SavedSearchResource;
use App\Models\SavedSearch;
use App\Subscriptions\UsageLimitExceeded;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class StoreSavedSearchController extends Controller
{
    public function __invoke(
        StoreSavedSearchRequest $request,
        OrganizationContext $context,
        CreateSavedSearch $action,
    ): JsonResponse {
        $organization = $context->organization();
        Gate::authorize('create', [SavedSearch::class, $organization]);
        $validated = $request->validated();

        try {
            $result = $action->create(
                $organization,
                $request->user(),
                $validated,
                $validated['reason_code'],
                $validated['idempotency_key'],
            );
        } catch (UsageLimitExceeded $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => [
                    'saved_search' => [[
                        'code' => 'saved_search_limit_reached',
                        'limit' => $exception->limit,
                        'used' => $exception->used,
                    ]],
                ],
            ], 422);
        }

        $result->savedSearch->load([
            'owner:id,name',
            'currentVersion.category',
            'currentVersion.brand',
            'currentVersion.productModel',
        ])
            ->loadCount(['versions', 'matches']);

        return (new SavedSearchResource($result->savedSearch))
            ->additional([
                'meta' => [
                    'created' => $result->created,
                    'version_id' => $result->version->getKey(),
                ],
            ])
            ->response()
            ->setStatusCode($result->created ? 201 : 200);
    }
}
