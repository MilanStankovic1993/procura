<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\OrganizationResource;
use App\Tenancy\ActivateOrganization;
use Illuminate\Http\Request;

class ActivateOrganizationController extends Controller
{
    public function __invoke(
        Request $request,
        string $organization,
        ActivateOrganization $activateOrganization,
    ): OrganizationResource {
        $membership = $activateOrganization->activate($request->user(), $organization);

        return OrganizationResource::make($membership);
    }
}
