<?php

namespace App\Http\Controllers\Api\V1\Listings;

use App\Actions\Listings\DeleteListingImage;
use App\Http\Controllers\Controller;
use App\Models\Listing;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class DeleteListingImageController extends Controller
{
    public function __invoke(
        Request $request,
        string $listing,
        string $image,
        OrganizationContext $context,
        DeleteListingImage $images,
    ): Response {
        $record = Listing::query()
            ->forOrganization($context->organization())
            ->findOrFail($listing);
        $imageRecord = $record->images()->findOrFail($image);

        Gate::authorize('manageImages', $record);
        $images->delete($imageRecord, $request->user());

        return response()->noContent();
    }
}
