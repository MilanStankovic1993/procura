<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Actions\Organizations\ManageOrganizationMembers;
use App\Enums\Organizations\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Organizations\UpdateOrganizationMemberRoleRequest;
use App\Http\Resources\V1\OrganizationMemberResource;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class UpdateOrganizationMemberRoleController extends Controller
{
    public function __invoke(
        UpdateOrganizationMemberRoleRequest $request,
        string $membership,
        OrganizationContext $context,
        ManageOrganizationMembers $members,
    ): JsonResponse {
        Gate::authorize('manageMembers', $context->organization());

        $updatedMembership = $members->updateRole(
            organization: $context->organization(),
            actor: $request->user(),
            membershipId: $membership,
            newRole: OrganizationRole::from($request->string('role')->toString()),
        );

        return (new OrganizationMemberResource($updatedMembership))->response();
    }
}
