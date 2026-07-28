<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Actions\Organizations\ManageOrganizationInvitations;
use App\Http\Controllers\Controller;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class RevokeOrganizationInvitationController extends Controller
{
    public function __invoke(
        Request $request,
        string $invitation,
        OrganizationContext $context,
        ManageOrganizationInvitations $invitations,
    ): Response {
        Gate::authorize('inviteMembers', $context->organization());

        $invitations->revoke(
            organization: $context->organization(),
            actor: $request->user(),
            invitationId: $invitation,
        );

        return response()->noContent();
    }
}
