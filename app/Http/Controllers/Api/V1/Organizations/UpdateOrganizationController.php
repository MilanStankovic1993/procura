<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Actions\Organizations\UpdateOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Organizations\UpdateOrganizationRequest;
use App\Http\Resources\V1\OrganizationResource;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class UpdateOrganizationController extends Controller
{
    public function __invoke(
        UpdateOrganizationRequest $request,
        OrganizationContext $context,
        UpdateOrganization $updateOrganization,
    ): JsonResponse {
        Gate::authorize('update', $context->organization());

        $organization = $updateOrganization->update(
            organization: $context->organization(),
            actor: $request->user(),
            name: $request->string('name')->toString(),
        );

        $membership = $context->membership()->setRelation('organization', $organization);

        return (new OrganizationResource($membership))->response();
    }
}
