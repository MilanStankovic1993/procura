<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Actions\Organizations\CreateBusinessOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Organizations\StoreOrganizationRequest;
use App\Http\Resources\V1\OrganizationResource;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class CreateOrganizationController extends Controller
{
    public function __invoke(
        StoreOrganizationRequest $request,
        CreateBusinessOrganization $createBusinessOrganization,
    ): JsonResponse {
        Gate::authorize('create', Organization::class);

        $membership = $createBusinessOrganization->create(
            user: $request->user(),
            name: $request->string('name')->toString(),
        );

        return (new OrganizationResource($membership))
            ->response()
            ->setStatusCode(201);
    }
}
