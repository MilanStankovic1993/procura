<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Actions\Organizations\ManageOrganizationMembers;
use App\Http\Controllers\Controller;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class RemoveOrganizationMemberController extends Controller
{
    public function __invoke(
        Request $request,
        string $membership,
        OrganizationContext $context,
        ManageOrganizationMembers $members,
    ): Response {
        Gate::authorize('manageMembers', $context->organization());

        $members->remove(
            organization: $context->organization(),
            actor: $request->user(),
            membershipId: $membership,
        );

        return response()->noContent();
    }
}
