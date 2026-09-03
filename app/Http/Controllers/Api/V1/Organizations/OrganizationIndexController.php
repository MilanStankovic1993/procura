<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\OrganizationResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrganizationIndexController extends Controller
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $memberships = $request->user()
            ->memberships()
            ->with('organization')
            ->orderBy('joined_at')
            ->orderBy('id')
            ->get();

        return OrganizationResource::collection($memberships);
    }
}
