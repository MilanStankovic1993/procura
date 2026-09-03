<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\UserResource;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\Request;

class AuthenticatedUserController extends Controller
{
    public function __invoke(
        Request $request,
        OrganizationContext $organizationContext,
    ): UserResource {
        return UserResource::make($request->user())
            ->withCurrentOrganization($organizationContext->membership());
    }
}
