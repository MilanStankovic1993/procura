<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Actions\Organizations\ManageOrganizationMembers;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\OrganizationMemberResource;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TransferOrganizationOwnershipController extends Controller
{
    public function __invoke(
        Request $request,
        string $membership,
        OrganizationContext $context,
        ManageOrganizationMembers $members,
    ): JsonResponse {
        Gate::authorize('transferOwnership', $context->organization());

        $newOwner = $members->transferOwnership(
            organization: $context->organization(),
            actor: $request->user(),
            membershipId: $membership,
        );

        return (new OrganizationMemberResource($newOwner))->response();
    }
}
