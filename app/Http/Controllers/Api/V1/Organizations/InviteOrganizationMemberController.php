<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Actions\Organizations\ManageOrganizationInvitations;
use App\Enums\Organizations\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Organizations\StoreOrganizationInvitationRequest;
use App\Http\Resources\V1\OrganizationInvitationResource;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class InviteOrganizationMemberController extends Controller
{
    public function __invoke(
        StoreOrganizationInvitationRequest $request,
        OrganizationContext $context,
        ManageOrganizationInvitations $invitations,
    ): JsonResponse {
        Gate::authorize('inviteMembers', $context->organization());

        $invitation = $invitations->invite(
            organization: $context->organization(),
            actor: $request->user(),
            email: $request->string('email')->toString(),
            role: OrganizationRole::from($request->string('role')->toString()),
        );

        return (new OrganizationInvitationResource($invitation))
            ->response()
            ->setStatusCode(201);
    }
}
