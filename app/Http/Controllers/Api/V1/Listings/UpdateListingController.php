<?php

namespace App\Http\Controllers\Api\V1\Listings;

use App\Actions\Listings\UpdateListing;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Listings\UpdateListingRequest;
use App\Http\Resources\V1\ListingResource;
use App\Models\Listing;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

class UpdateListingController extends Controller
{
    public function __invoke(
        UpdateListingRequest $request,
        string $listing,
        OrganizationContext $context,
        UpdateListing $listings,
    ): JsonResource {
        $record = Listing::query()
            ->forOrganization($context->organization())
            ->findOrFail($listing);

        Gate::authorize('update', $record);

        $record = $listings
            ->update($record, $request->user(), $request->validated())
            ->load(['marketplaceSource', 'currency', 'images', 'snapshots'])
            ->loadCount(['images', 'snapshots']);

        return new ListingResource($record);
    }
}
