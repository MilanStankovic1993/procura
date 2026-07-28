<?php

namespace App\Http\Controllers\Api\V1\Listings;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ListingResource;
use App\Models\Listing;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

class ShowListingController extends Controller
{
    public function __invoke(
        string $listing,
        OrganizationContext $context,
    ): JsonResource {
        $record = Listing::query()
            ->forOrganization($context->organization())
            ->with([
                'marketplaceSource',
                'currency',
                'images',
                'snapshots' => static fn ($query) => $query->limit(25),
            ])
            ->withCount(['images', 'snapshots'])
            ->findOrFail($listing);

        Gate::authorize('view', $record);

        return new ListingResource($record);
    }
}
