<?php

namespace App\Http\Controllers\Api\V1\Listings;

use App\Actions\Listings\StoreListingImages;
use App\Enums\Listings\ListingImageKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Listings\StoreListingImagesRequest;
use App\Http\Resources\V1\ListingImageResource;
use App\Models\Listing;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class StoreListingImagesController extends Controller
{
    public function __invoke(
        StoreListingImagesRequest $request,
        string $listing,
        OrganizationContext $context,
        StoreListingImages $images,
    ): AnonymousResourceCollection {
        $record = Listing::query()
            ->forOrganization($context->organization())
            ->findOrFail($listing);

        Gate::authorize('manageImages', $record);
        $stored = $images->store(
            $record,
            $request->user(),
            ListingImageKind::from($request->validated('kind')),
            $request->file('images'),
        );

        return ListingImageResource::collection($stored);
    }
}
