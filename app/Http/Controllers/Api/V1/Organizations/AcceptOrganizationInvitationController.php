<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Actions\Organizations\ManageOrganizationInvitations;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Organizations\AcceptOrganizationInvitationRequest;
use App\Http\Resources\V1\OrganizationResource;
use Illuminate\Http\JsonResponse;

class AcceptOrganizationInvitationController extends Controller
{
    public function __invoke(
        AcceptOrganizationInvitationRequest $request,
        ManageOrganizationInvitations $invitations,
    ): JsonResponse {
        $membership = $invitations->accept(
            user: $request->user(),
            plainTextToken: $request->string('token')->toString(),
        );

        return (new OrganizationResource($membership))
            ->response()
            ->setStatusCode(200);
    }
}
