<?php

namespace App\Http\Controllers\Api\V1\Listings;

use App\Actions\Listings\CreateListing;
use App\Enums\Listings\MarketplaceConnectorType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Listings\StoreListingRequest;
use App\Http\Resources\V1\ListingResource;
use App\Models\Listing;
use App\Models\MarketplaceSource;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class StoreListingController extends Controller
{
    public function __invoke(
        StoreListingRequest $request,
        OrganizationContext $context,
        CreateListing $listings,
    ): JsonResponse {
        $organization = $context->organization();
        Gate::authorize('create', [Listing::class, $organization]);
        $validated = $request->validated();
        $source = MarketplaceSource::query()
            ->where('key', $validated['marketplace_source_key'])
            ->where('connector_type', MarketplaceConnectorType::Manual)
            ->where('active', true)
            ->firstOrFail();
        unset($validated['marketplace_source_key']);

        $listing = $listings->create(
            $organization,
            $request->user(),
            $source,
            $validated,
        )->load(['marketplaceSource', 'currency'])->loadCount(['images', 'snapshots']);

        return (new ListingResource($listing))
            ->response()
            ->setStatusCode(201);
    }
}
